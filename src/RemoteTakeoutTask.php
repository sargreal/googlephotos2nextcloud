<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Imports a Google Takeout that has already been extracted inside Nextcloud.
 *
 * Media files are moved (not re-uploaded) into <target>/Y/m, files whose contents already exist in
 * the target are deleted (they end up in the Nextcloud trash bin), and files from album directories
 * are added to the corresponding Nextcloud Photos album.
 *
 * The modification and creation time are set to the taken time (see RemoteMediaTask for how the date in
 * Nextcloud Photos is kept correct). Files in the target with a wrong date in Nextcloud Photos (e.g. moved by an
 * earlier version) are repaired when their sidecar is found.
 *
 * A takeout split over several exports can be imported at once: directories with the same name (e.g. an album
 * or "Photos from 2020") are treated as one, so sidecars and album metadata are found in any of the exports.
 */
class RemoteTakeoutTask extends RemoteMediaTask {

    const ALBUM_METADATA_FILENAMES = ['metadata.json', 'metadaten.json', 'métadonnées.json', 'metadatos.json', 'metadati.json', 'metadane.json', 'metadados.json'];

    public function __construct(
            \Sabre\DAV\Client $client,
            Attempt $attempt,
            private array $takeout_paths,
            string $target_path,
            string $albums_base_path,
            bool $dry_run,
            bool $delete_duplicates
    ) {
        parent::__construct($client, $attempt, $target_path, $albums_base_path, $dry_run, $delete_duplicates);
        $this->takeout_paths = array_map(fn(string $path) => rtrim($path, '/'), $takeout_paths);
    }

    protected function relativeBases(): array {
        return [...$this->takeout_paths, $this->target_path];
    }

    public function __invoke(): array {
        foreach ($this->takeout_paths as $takeout_path) {
            if (self::isInside($this->target_path, $takeout_path)) {
                throw new \Exception('Target directory must not be inside the takeout directory');
            }
        }

        $this->indexTarget($this->takeout_paths);

        // group directories by name, so a directory split over several exports is processed as one
        $groups = [];
        foreach ($this->takeout_paths as $takeout_path) {
            $this->log('Walking takeout in ' . $takeout_path);
            $this->walk($takeout_path, function (string $directory, array $children) use (&$groups) {
                $groups[basename($directory)][$directory] = $children;
            });
        }
        foreach ($groups as $name => $directories) {
            $this->processDirectories((string) $name, $directories);
        }

        $all_sidecars = [];
        foreach ($groups as $directories) {
            foreach ($directories as $children) {
                foreach (array_keys($children) as $path) {
                    if (str_ends_with(strtolower($path), '.json')) {
                        $all_sidecars[basename($path)][] = $path;
                    }
                }
            }
        }
        IO::write('---');
        $this->log('Checking dates of files in ' . $this->target_path);
        $this->repairDates($all_sidecars);

        $this->log('Moved ' . $this->stats['moved'] . ' files, '
                . ($this->delete_duplicates ? 'deleted ' : 'found ') . $this->stats['duplicates'] . ' duplicates, '
                . 'made ' . $this->stats['album_additions'] . ' album additions, '
                . 'repaired dates of ' . $this->stats['dates_repaired'] . ' files, '
                . $this->stats['errors'] . ' errors');
        return $this->stats;
    }

    private function fetchJson(string $path): mixed {
        $attempt = $this->attempt;
        $response = $attempt('request', 'GET', self::encode($path));
        if ($response['statusCode'] !== 200) {
            throw new \Exception('Could not read "' . $path . '" (' . $response['statusCode'] . ')');
        }
        return json_decode($response['body'], true);
    }

    private function albumTitle(string $directory_name, array $json_paths): ?string {
        foreach ($json_paths as $json_path) {
            if (in_array(mb_strtolower(basename($json_path)), self::ALBUM_METADATA_FILENAMES, true) === false) {
                continue;
            }
            $metadata = $this->fetchJson($json_path);
            if (is_array($metadata) === false || array_key_exists('title', $metadata) === false) {
                continue;
            }
            return empty($metadata['title']) ? $directory_name : str_replace('/', '-', $metadata['title']);
        }
        return null;
    }

    /**
     * @param array<string, string[]> $sidecars json filename => paths
     * @param array<string, string[]> $sidecar_index SidecarMatcher::index() of the json filenames
     * @return int[] timestamps found in the sidecars of the media file
     */
    private function sidecarTimestamps(string $media_filename, array $sidecars, array $sidecar_index): array {
        $timestamps = [];
        foreach (SidecarMatcher::indexedCandidates($media_filename, $sidecar_index) as $json_name) {
            foreach ($sidecars[$json_name] as $json_path) {
                $timestamp = SidecarMatcher::timestamp($this->fetchJson($json_path));
                if ($timestamp !== null) {
                    $timestamps[] = $timestamp;
                }
            }
        }
        return $timestamps;
    }

    private function takenTime(string $path, array $props, array $sidecars, array $sidecar_index): \DateTimeImmutable {
        $timestamps = $this->sidecarTimestamps(basename($path), $sidecars, $sidecar_index);
        if (count($timestamps) > 0) {
            $this->log('[' . basename($path) . '] - Taken time from sidecar metadata');
            return new \DateTimeImmutable('@' . $timestamps[0]);
        }

        $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
        if (is_numeric($original)) {
            $this->log('[' . basename($path) . '] - No sidecar metadata, using taken time extracted by Nextcloud');
            return new \DateTimeImmutable('@' . $original);
        }

        $this->log('[' . basename($path) . '] - No sidecar metadata, falling back to last modified time');
        return new \DateTimeImmutable($props['{DAV:}getlastmodified'] ?? 'now');
    }

    /**
     * Repairs files below <target>/Y/m whose date in Nextcloud Photos is outside that month, when a sidecar
     * with a taken time in that month is found.
     *
     * @param array<string, string[]> $sidecars json filename => paths
     */
    private function repairDates(array $sidecars): void {
        $sidecar_index = SidecarMatcher::index(array_keys($sidecars));
        $pattern = '#^' . preg_quote($this->target_path, '#') . '/(\d{4})/(\d{2})/[^/]+$#';
        foreach ($this->target_by_size as $entries) {
            foreach ($entries as $path => $entry) {
                if ($entry['real'] !== $path || preg_match($pattern, $path, $matches) !== 1) {
                    continue; // not moved yet (dry run) or not in the year/month structure
                }
                $props = $entry['props'];
                $month_start = gmmktime(0, 0, 0, (int) $matches[2], 1, (int) $matches[1]);
                $month_end = gmmktime(0, 0, 0, (int) $matches[2] + 1, 1, (int) $matches[1]);
                $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
                if (is_numeric($original) && $original >= $month_start - self::DATE_TOLERANCE && $original < $month_end + self::DATE_TOLERANCE) {
                    continue;
                }

                try {
                    // "name(n).ext" may also have been renamed because of a name collision in the target
                    $timestamps = [
                        ...$this->sidecarTimestamps(basename($path), $sidecars, $sidecar_index),
                        ...$this->sidecarTimestamps(preg_replace('/\(\d+\)(?=\.[^.]*$)/', '', basename($path)), $sidecars, $sidecar_index),
                    ];
                    $timestamp = null;
                    foreach ($timestamps as $candidate) {
                        if ($candidate >= $month_start && $candidate < $month_end) {
                            $timestamp = $candidate;
                            break;
                        }
                    }
                    $modified = strtotime($props['{DAV:}getlastmodified'] ?? '');
                    if ($timestamp === null || ($modified === $timestamp && isset($this->interrupted_repairs[$path]) === false)) {
                        continue; // no sidecar, or dates were already set and the date in Photos comes from EXIF
                    }
                    $this->repairDate($path, $props, $timestamp);
                } catch (\Exception $e) {
                    $this->stats['errors']++;
                    $this->log('[' . basename($path) . '] - Failed repairing date: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * @param array<string, array> $directories directories with the same name => their children
     */
    private function processDirectories(string $name, array $directories): void {
        $json_paths = [];
        $media = [];
        foreach ($directories as $children) {
            foreach ($children as $path => $props) {
                if (self::isCollection($props)) {
                    continue;
                } elseif (str_ends_with(strtolower($path), '.json')) {
                    $json_paths[] = $path;
                } elseif (str_starts_with(basename($path), '.') === false) {
                    $media[$path] = $props;
                }
            }
        }
        if (count($media) === 0) {
            return;
        }

        $sidecars = [];
        foreach ($json_paths as $json_path) {
            $sidecars[basename($json_path)][] = $json_path;
        }
        $sidecar_index = SidecarMatcher::index(array_keys($sidecars));

        IO::write('---');
        $album = $this->albumTitle($name, $json_paths);
        $this->log('Processing "' . $name . '"' . (count($directories) > 1 ? ' from ' . count($directories) . ' exports' : '')
                . ' with ' . count($media) . ' media files' . ($album !== null ? ', album "' . $album . '"' : ''));

        $index = 0;
        foreach ($media as $path => $props) {
            $index++;
            $name = basename($path);
            try {
                $duplicate = $this->findDuplicate($path, $props);
                if ($duplicate !== null) {
                    $this->log('[' . $name . '] - ' . $index . '/' . count($media) . ' identical to "' . $this->relative($duplicate) . '"');
                    if ($album !== null) {
                        $existing = $this->target_by_size[(int) $props['{DAV:}getcontentlength']][$duplicate];
                        $this->addToAlbum($album, $duplicate, $existing['props']);
                    }
                    $this->stats['duplicates']++;
                    if ($this->delete_duplicates) {
                        $this->log('[' . $name . '] - Deleting duplicate');
                        $this->delete($path);
                    }
                    continue;
                }

                $taken_time = $this->takenTime($path, $props, $sidecars, $sidecar_index);
                $target_directory = $this->target_path . $taken_time->format('/Y/m');
                $this->ensureDirectory($target_directory);
                $this->setDates($path, $taken_time->getTimestamp());

                if (self::photosDateMatches($props, $taken_time->getTimestamp())) {
                    if ($album !== null) {
                        // file ids survive a move, so adding before moving keeps album membership even if the move fails
                        $this->addToAlbum($album, $path, $props);
                    }
                    $destination = $this->move($path, $target_directory);
                    $this->log('[' . $name . '] - ' . $index . '/' . count($media) . ' moved to "' . $this->relative($destination) . '"');
                } else {
                    // a copy makes Nextcloud Photos calculate its date again, a move does not
                    $destination = $this->move($path, $target_directory, 'COPY');
                    $this->log('[' . $name . '] - ' . $index . '/' . count($media) . ' copied to "' . $this->relative($destination) . '" (to update the date in Nextcloud Photos)');
                    $props = $this->dry_run ? $props : $this->properties($destination);
                    if ($album !== null) {
                        $this->addToAlbum($album, $destination, $props);
                    }
                    $this->delete($path);
                }
                if (isset($this->hashes[$path])) {
                    $this->hashes[$destination] = $this->hashes[$path];
                }
                $this->index($destination, $props, $this->dry_run ? $path : $destination);
                $this->stats['moved']++;
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->log('[' . $name . '] - Failed: ' . $e->getMessage());
            }
        }
    }
}
