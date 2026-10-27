<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Imports a Google Takeout that has already been extracted inside Nextcloud.
 *
 * Media files are moved (not re-uploaded) into <target>/Y/m, files whose contents already exist in
 * the target are deleted (they end up in the Nextcloud trash bin), and files from album directories
 * are added to the corresponding Nextcloud Photos album.
 *
 * The modification and creation time are set to the taken time. Nextcloud Photos sorts on a date it only
 * calculates when a file is created (from EXIF, else from the modification time), and a MOVE keeps the old
 * value, so files where that date is wrong are copied server-side and the original is deleted instead.
 * Files in the target with such a wrong date (e.g. moved by an earlier version) are repaired the same way.
 *
 * A takeout split over several exports can be imported at once: directories with the same name (e.g. an album
 * or "Photos from 2020") are treated as one, so sidecars and album metadata are found in any of the exports.
 *
 * Internally all paths are decoded DAV paths (e.g. /remote.php/dav/files/user/Takeout/Photos from 2020),
 * they are only encoded when sent to the server.
 */
class RemoteTakeoutTask {

    const TEMPORARY_PREFIX = '.gp2nc-tmp-';
    const DATE_TOLERANCE = 86400; // EXIF dates are local time, sidecar dates are UTC
    const ALBUM_METADATA_FILENAMES = ['metadata.json', 'metadaten.json', 'métadonnées.json', 'metadatos.json', 'metadati.json', 'metadane.json', 'metadados.json'];
    const PROPERTIES = [
        '{DAV:}getcontentlength',
        '{DAV:}getlastmodified',
        '{DAV:}resourcetype',
        '{http://owncloud.org/ns}checksums',
        '{http://owncloud.org/ns}fileid',
        '{http://nextcloud.org/ns}metadata-photos-original_date_time',
    ];

    /** @var array<int, array<string, array{props: array, real: string}>> size => [path => entry] */
    private array $target_by_size = [];
    /** @var array<string, string> real path => md5 */
    private array $hashes = [];
    /** @var array<string, array<string, true>>|null album name => [fileid => true] */
    private ?array $albums = null;
    private array $created_directories = [];
    /** @var array<string, true> files whose date repair was interrupted */
    private array $interrupted_repairs = [];
    private array $stats = ['moved' => 0, 'duplicates' => 0, 'album_additions' => 0, 'dates_repaired' => 0, 'errors' => 0];

    public function __construct(
            private \Sabre\DAV\Client $client,
            private Attempt $attempt,
            private array $takeout_paths,
            private string $target_path,
            private string $albums_base_path,
            private bool $dry_run,
            private bool $delete_duplicates
    ) {
        $this->takeout_paths = array_map(fn(string $path) => rtrim($path, '/'), $takeout_paths);
        $this->target_path = rtrim($target_path, '/');
        $this->albums_base_path = rtrim($albums_base_path, '/');
    }

    static function encode(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    static function isInside(string $path, string $directory): bool {
        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    private function log(string $message): void {
        IO::write(($this->dry_run ? '[dry-run] ' : '') . $message);
    }

    private function relative(string $path): string {
        foreach ([...$this->takeout_paths, $this->target_path] as $base) {
            if (self::isInside($path, $base)) {
                return basename($base) . substr($path, strlen($base));
            }
        }
        return $path;
    }

    public function __invoke(): array {
        foreach ($this->takeout_paths as $takeout_path) {
            if (self::isInside($this->target_path, $takeout_path)) {
                throw new \Exception('Target directory must not be inside the takeout directory');
            }
        }

        $this->log('Indexing existing files in ' . $this->target_path);
        $indexed = 0;
        $target_exists = $this->exists($this->target_path);
        if ($target_exists === false) {
            $this->log('Creating ' . $this->target_path);
            $this->mkdirs($this->target_path);
        }
        if ($target_exists) {
            $this->walk($this->target_path, function (string $directory, array $children) use (&$indexed) {
                foreach ($children as $path => $props) {
                    if (self::isCollection($props) || isset($props['{DAV:}getcontentlength']) === false) {
                        continue;
                    } elseif (str_starts_with(basename($path), self::TEMPORARY_PREFIX)) {
                        $this->log('Removing leftover "' . $this->relative($path) . '" of an interrupted run');
                        $this->delete($path);
                        $this->interrupted_repairs[dirname($path) . '/' . substr(basename($path), strlen(self::TEMPORARY_PREFIX))] = true;
                        continue;
                    }
                    $this->index($path, $props, $path);
                    $indexed++;
                }
            }, $this->takeout_paths);
        }
        $this->log('Indexed ' . $indexed . ' existing files');

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

    static function isCollection(array $props): bool {
        $type = $props['{DAV:}resourcetype'] ?? null;
        return $type instanceof \Sabre\DAV\Xml\Property\ResourceType && $type->is('{DAV:}collection');
    }

    /**
     * @return array<string, array> decoded path => properties, without the directory itself
     */
    private function list(string $directory): array {
        $attempt = $this->attempt;
        $children = [];
        foreach ($attempt('propFind', self::encode($directory), self::PROPERTIES, 1) as $href => $props) {
            $path = rtrim(rawurldecode(parse_url($href, PHP_URL_PATH)), '/');
            if ($path !== $directory) {
                $children[$path] = $props;
            }
        }
        ksort($children, SORT_NATURAL);
        return $children;
    }

    private function walk(string $directory, callable $callback, array $excludes = []): void {
        $children = $this->list($directory);
        $callback($directory, $children);
        foreach ($children as $path => $props) {
            if (self::isCollection($props) === false) {
                continue;
            }
            foreach ($excludes as $exclude) {
                if (self::isInside($path, $exclude)) {
                    continue 2;
                }
            }
            $this->walk($path, $callback, $excludes);
        }
    }

    private function index(string $path, array $props, string $real): void {
        $this->target_by_size[(int) $props['{DAV:}getcontentlength']][$path] = ['props' => $props, 'real' => $real];
    }

    private function hash(string $real, array $props): string {
        if (isset($this->hashes[$real])) {
            return $this->hashes[$real];
        }

        foreach ($props['{http://owncloud.org/ns}checksums'] ?? [] as $checksum) {
            [$algo, $hash] = array_pad(explode(':', $checksum['value'] ?? '', 2), 2, '');
            if (strcasecmp($algo, 'md5') === 0 && $hash !== '') {
                return $this->hashes[$real] = strtolower($hash);
            }
        }

        $context = hash_init('md5');
        $handle = curl_init($this->client->getAbsoluteUrl(self::encode($real)));
        curl_setopt_array($handle, [
            CURLOPT_USERPWD => NEXTCLOUD_USER . ':' . NEXTCLOUD_PASSWORD,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_WRITEFUNCTION => function ($handle, string $data) use ($context) {
                hash_update($context, $data);
                return strlen($data);
            },
        ]);
        $attempts = 0;
        while (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
            if (++$attempts === 5) {
                throw new \Exception('Could not download "' . $real . '" to calculate its hash: ' . curl_error($handle));
            }
            IO::write('download failed, retrying in 10 seconds...');
            sleep(10);
            $context = hash_init('md5');
        }
        curl_close($handle);
        return $this->hashes[$real] = hash_final($context);
    }

    private function findDuplicate(string $path, array $props): ?string {
        $candidates = $this->target_by_size[(int) ($props['{DAV:}getcontentlength'] ?? -1)] ?? [];
        if (count($candidates) === 0) {
            return null;
        }
        $hash = $this->hash($path, $props);
        foreach ($candidates as $candidate_path => $candidate) {
            if ($candidate['real'] !== $path && $this->hash($candidate['real'], $candidate['props']) === $hash) {
                return $candidate_path;
            }
        }
        return null;
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

    private function exists(string $path): bool {
        $attempt = $this->attempt;
        return $attempt('request', 'HEAD', self::encode($path))['statusCode'] !== 404;
    }

    private function mkdirs(string $path): void {
        if ($this->dry_run || $this->exists($path)) {
            return;
        }
        $this->mkdirs(dirname($path));
        $attempt = $this->attempt;
        $response = $attempt('request', 'MKCOL', self::encode($path));
        if ($response['statusCode'] !== 201) {
            throw new \Exception('Could not create "' . $path . '" (' . $response['statusCode'] . ')');
        }
    }

    private function properties(string $path): array {
        $attempt = $this->attempt;
        return $attempt('propFind', self::encode($path), self::PROPERTIES, 0);
    }

    private function delete(string $path): void {
        if ($this->dry_run) {
            return;
        }
        $attempt = $this->attempt;
        $response = $attempt('request', 'DELETE', self::encode($path));
        if ($response['statusCode'] < 200 || $response['statusCode'] > 299) {
            throw new \Exception('Could not delete "' . $path . '" (' . $response['statusCode'] . ')');
        }
    }

    private function setDates(string $path, int $timestamp): void {
        if ($this->dry_run) {
            return;
        }
        $attempt = $this->attempt;
        if ($attempt('propPatch', self::encode($path), [
                    '{DAV:}lastmodified' => $timestamp,
                    '{http://nextcloud.org/ns}creation_time' => $timestamp,
                ]) !== true) {
            throw new \Exception('Could not set dates of "' . $path . '"');
        }
    }

    /**
     * Whether the date Nextcloud Photos sorts on is (close to) the given time.
     */
    static function photosDateMatches(array $props, int $timestamp): bool {
        $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
        return is_numeric($original) && abs((int) $original - $timestamp) <= self::DATE_TOLERANCE;
    }

    private function ensureDirectory(string $directory): void {
        if ($this->dry_run || isset($this->created_directories[$directory])) {
            return;
        }
        $relative = substr($directory, strlen($this->target_path));
        RemoteDirectory::create($this->attempt, self::encode($this->target_path), self::encode($relative));
        $this->created_directories[$directory] = true;
    }

    /**
     * @return string[] albums containing the file
     */
    private function albumsContaining(string $fileid): array {
        $this->albumFileIds(null);
        return array_values(array_filter(array_keys($this->albums), fn($album) => isset($this->albumFileIds((string) $album)[$fileid])));
    }

    private function albumFileIds(?string $album): array {
        $attempt = $this->attempt;
        if ($this->albums === null) {
            $this->albums = [];
            foreach ($attempt('propFind', self::encode($this->albums_base_path), ['{DAV:}resourcetype'], 1) as $href => $props) {
                $name = rawurldecode(basename(parse_url($href, PHP_URL_PATH)));
                if (rtrim(rawurldecode(parse_url($href, PHP_URL_PATH)), '/') !== $this->albums_base_path) {
                    $this->albums[$name] = null; // contents are loaded lazily
                }
            }
        }
        if ($album === null) {
            return [];
        }

        if (array_key_exists($album, $this->albums) === false) {
            $this->log('Creating album "' . $album . '"');
            if ($this->dry_run === false) {
                $response = $attempt('request', 'MKCOL', self::encode($this->albums_base_path . '/' . $album));
                if ($response['statusCode'] !== 201) {
                    throw new \Exception('Could not create album "' . $album . '" (' . $response['statusCode'] . ')');
                }
            }
            $this->albums[$album] = [];
        } elseif ($this->albums[$album] === null) {
            $this->albums[$album] = [];
            foreach ($attempt('propFind', self::encode($this->albums_base_path . '/' . $album), ['{DAV:}resourcetype'], 1) as $href => $props) {
                if (preg_match('/^(\d+)-/', rawurldecode(basename(parse_url($href, PHP_URL_PATH))), $matches) === 1) {
                    $this->albums[$album][$matches[1]] = true;
                }
            }
        }
        return $this->albums[$album];
    }

    private function addToAlbum(string $album, string $path, array $props): void {
        $fileid = (string) ($props['{http://owncloud.org/ns}fileid'] ?? '');
        if (isset($this->albumFileIds($album)[$fileid])) {
            return;
        }
        $this->log('[' . basename($path) . '] - Adding to album "' . $album . '"');
        if ($this->dry_run === false) {
            $attempt = $this->attempt;
            $response = $attempt('request', 'COPY', self::encode($path), headers: [
                'Destination' => $this->client->getAbsoluteUrl(self::encode($this->albums_base_path . '/' . $album . '/' . basename($path))),
            ]);
            if ($response['statusCode'] < 200 || $response['statusCode'] > 299) {
                throw new \Exception('Could not add to album "' . $album . '" (' . $response['statusCode'] . ')');
            }
        }
        $this->albums[$album][$fileid] = true;
        $this->stats['album_additions']++;
    }

    /**
     * Moves (or copies) the file without overwriting, picking "name(n).ext" when the name is taken.
     */
    private function move(string $path, string $directory, string $method = 'MOVE'): string {
        $attempt = $this->attempt;
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.' . $extension;
        for ($increment = 0; $increment < 1000; $increment++) {
            $destination = $directory . '/' . $filename . ($increment > 0 ? '(' . $increment . ')' : '') . $suffix;
            if ($this->dry_run) {
                if ($this->isIndexed($destination)) {
                    continue;
                }
                return $destination;
            }
            $response = $attempt('request', $method, self::encode($path), headers: [
                'Destination' => $this->client->getAbsoluteUrl(self::encode($destination)),
                'Overwrite' => 'F',
            ]);
            if ($response['statusCode'] === 201 || $response['statusCode'] === 204) {
                return $destination;
            } elseif ($response['statusCode'] !== 412) {
                throw new \Exception('Could not ' . strtolower($method) . ' to "' . $destination . '" (' . $response['statusCode'] . ')');
            }
        }
        throw new \Exception('Could not find an available filename in "' . $directory . '"');
    }

    private function isIndexed(string $path): bool {
        foreach ($this->target_by_size as $entries) {
            if (isset($entries[$path])) {
                return true;
            }
        }
        return false;
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
     * Copying makes Nextcloud Photos calculate the date again; the copy replaces the original, so it gets a
     * new file id and is added to the albums of the original again.
     */
    private function repairDate(string $path, array $props, int $timestamp): void {
        $this->log('[' . $this->relative($path) . '] - Repairing date to ' . gmdate('Y-m-d H:i:s', $timestamp));
        $fileid = (string) ($props['{http://owncloud.org/ns}fileid'] ?? '');
        $albums = $this->albumsContaining($fileid);
        $this->setDates($path, $timestamp);
        $this->stats['dates_repaired']++;
        if ($this->dry_run) {
            return;
        }

        $attempt = $this->attempt;
        $temporary = dirname($path) . '/' . self::TEMPORARY_PREFIX . basename($path);
        foreach ([[$path, $temporary, 'COPY'], [$temporary, $path, 'MOVE']] as [$source, $destination, $method]) {
            $response = $attempt('request', $method, self::encode($source), headers: [
                'Destination' => $this->client->getAbsoluteUrl(self::encode($destination)),
                'Overwrite' => 'T',
            ]);
            if ($response['statusCode'] < 200 || $response['statusCode'] > 299) {
                throw new \Exception('Could not ' . strtolower($method) . ' "' . $source . '" (' . $response['statusCode'] . ')');
            }
        }

        $new_props = $this->properties($path);
        $this->target_by_size[(int) $props['{DAV:}getcontentlength']][$path]['props'] = $new_props;
        foreach ($albums as $album) {
            unset($this->albums[$album][$fileid]);
            $this->addToAlbum($album, $path, $new_props);
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
