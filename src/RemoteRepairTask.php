<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Repairs the year/month structure of media files that are already in the target directory.
 *
 * - reorganize: moves files that are not in the year/month directory of their date. The date comes from EXIF,
 *   then from the file name, then from the creation time, then from the modification time. Files that are already
 *   in a year/month directory are only moved when their date comes from EXIF or the file name, as the creation and
 *   modification time are often just the time of upload.
 * - filename-dates: for files with a date in their name (e.g. IMG_20190504_142512.jpg) whose creation or
 *   modification time is more than a day off, sets both to that date (the Photos and Memories timelines use them
 *   when there is no EXIF date) and moves them to its year/month directory. Other files are left unchanged.
 * - folder-albums: only adds the files in album directories to their album, without moving anything.
 *
 * Directories directly below the target that are not years (e.g. <target>/Holiday 2019) are albums: before a file
 * in them is moved, it is added to the Nextcloud Photos album with the directory name (unless disabled).
 * Files are moved with their albums and identical files are deleted, like the takeout import does.
 *
 * With similar duplicates enabled, reorganize first treats files with the same name (ignoring "(n)") and a taken
 * time within a day as the same photo, e.g. an original and a recompressed copy: it keeps the largest file, adds
 * the albums of the others to it and deletes them. At the end, files with the same name that remain in one
 * directory are reported, to be checked manually.
 */
class RemoteRepairTask extends RemoteMediaTask {

    const JOBS = ['reorganize', 'filename-dates', 'folder-albums'];

    public function __construct(
            \Sabre\DAV\Client $client,
            Attempt $attempt,
            private string $job,
            string $target_path,
            string $albums_base_path,
            bool $dry_run,
            bool $delete_duplicates,
            private array $exclude_paths = [],
            private bool $folder_albums = true,
            private bool $similar_duplicates = false
    ) {
        if (in_array($job, self::JOBS, true) === false) {
            throw new \Exception('Unknown job "' . $job . '", use one of: ' . implode(', ', self::JOBS));
        }
        parent::__construct($client, $attempt, $target_path, $albums_base_path, $dry_run, $delete_duplicates);
        $this->exclude_paths = array_map(fn(string $path) => rtrim($path, '/'), $exclude_paths);
    }

    protected function albumsOf(string $path, array $props): array {
        $albums = parent::albumsOf($path, $props);
        $folder_album = $this->folder_albums ? $this->folderAlbum($path) : null;
        if ($folder_album !== null && in_array($folder_album, $albums, true) === false) {
            $albums[] = $folder_album;
        }
        return $albums;
    }

    public function __invoke(): array {
        $this->stats['similar_duplicates'] = 0;
        $this->indexTarget($this->exclude_paths);

        if ($this->job === 'reorganize' && $this->similar_duplicates) {
            IO::write('---');
            $this->log('Looking for the same photo in different files (same name, taken within a day)');
            $this->deleteSimilarDuplicates();
        }

        IO::write('---');
        $this->log(match ($this->job) {
                    'reorganize' => 'Moving files that are not in the year/month directory of their date',
                    'filename-dates' => 'Using the date in the file name of files whose creation or modification time is off',
                    'folder-albums' => 'Adding files in album directories to their album',
                });

        // the index changes while files are moved, so work on a copy
        $files = [];
        foreach ($this->target_by_size as $entries) {
            foreach ($entries as $path => $entry) {
                $files[$path] = $entry['props'];
            }
        }
        ksort($files, SORT_NATURAL);

        foreach ($files as $path => $props) {
            if (self::isMedia($props) === false || $this->isIndexed($path) === false) {
                continue; // not a photo or video, or deleted as a duplicate
            }
            try {
                $album = $this->folder_albums ? $this->folderAlbum($path) : null;
                if ($this->job === 'folder-albums') {
                    if ($album !== null) {
                        $this->addToAlbum($album, $path, $props);
                    }
                    continue;
                }
                $timestamp = $this->job === 'reorganize' ? $this->reorganizeDate($path, $props) : $this->filenameDate($path, $props);
                if ($timestamp !== null) {
                    if ($album !== null) {
                        // the directory is the only record of the album, so add to it before the file leaves
                        $this->addToAlbum($album, $path, $props);
                    }
                    $this->relocate($path, $props, $timestamp);
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->log('[' . $this->relative($path) . '] - Failed: ' . $e->getMessage());
            }
        }

        if ($this->job !== 'folder-albums') {
            $this->reportSameNames();
        }

        IO::write('---');
        $this->log('Moved ' . $this->stats['moved'] . ' files, '
                . ($this->delete_duplicates ? 'deleted ' : 'found ') . $this->stats['duplicates'] . ' duplicates'
                . ($this->similar_duplicates && $this->job === 'reorganize' ? ' and ' . $this->stats['similar_duplicates'] . ' similar duplicates' : '') . ', '
                . 'repaired dates of ' . $this->stats['dates_repaired'] . ' files, '
                . 'made ' . $this->stats['album_additions'] . ' album additions, '
                . $this->stats['errors'] . ' errors');
        return $this->stats;
    }

    /**
     * @return array{int, int}|null start and end of the month of the year/month directory the file is in
     */
    private function yearMonthDirectory(string $path): ?array {
        $pattern = '#^' . preg_quote($this->target_path, '#') . '/(\d{4})/(0[1-9]|1[0-2])$#';
        if (preg_match($pattern, dirname($path), $matches) !== 1) {
            return null;
        }
        return [gmmktime(0, 0, 0, (int) $matches[2], 1, (int) $matches[1]), gmmktime(0, 0, 0, (int) $matches[2] + 1, 1, (int) $matches[1])];
    }

    private function reorganizeDate(string $path, array $props): ?int {
        $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
        $filename_date = FilenameDate::parse(basename($path));
        if (self::hasExifDate($props) && is_numeric($original)) {
            [$timestamp, $source, $reliable] = [(int) $original, 'EXIF', true];
        } elseif ($filename_date !== null) {
            [$timestamp, $source, $reliable] = [$filename_date, 'file name', true];
        } elseif ((int) ($props['{http://nextcloud.org/ns}creation_time'] ?? 0) > 0) {
            [$timestamp, $source, $reliable] = [(int) $props['{http://nextcloud.org/ns}creation_time'], 'creation time', false];
        } else {
            [$timestamp, $source, $reliable] = [strtotime($props['{DAV:}getlastmodified'] ?? 'now'), 'modification time', false];
        }

        $month = $this->yearMonthDirectory($path);
        if ($month !== null) {
            // EXIF dates are local time, so allow some tolerance around the month
            if ($reliable === false || ($timestamp >= $month[0] - self::DATE_TOLERANCE && $timestamp < $month[1] + self::DATE_TOLERANCE)) {
                return null;
            }
        }
        $this->log('[' . $this->relative($path) . '] - Date ' . gmdate('Y-m-d H:i:s', $timestamp) . ' from ' . $source);
        return $timestamp;
    }

    /**
     * Within each group of media files with the same original name, keeps the largest file and deletes the files
     * taken within a day of it, after adding their albums to it.
     */
    private function deleteSimilarDuplicates(): void {
        $groups = [];
        foreach ($this->target_by_size as $entries) {
            foreach ($entries as $path => $entry) {
                if (self::isMedia($entry['props'])) {
                    $groups[self::originalName(basename($path))][$path] = $entry['props'];
                }
            }
        }

        foreach ($groups as $files) {
            if (count($files) < 2) {
                continue;
            }
            uasort($files, fn(array $a, array $b) => (int) $b['{DAV:}getcontentlength'] <=> (int) $a['{DAV:}getcontentlength']);
            while (count($files) > 1) {
                $kept = array_key_first($files);
                $kept_props = array_shift($files);
                $kept_time = self::takenTimestamp($kept, $kept_props);
                foreach ($files as $path => $props) {
                    $time = self::takenTimestamp($path, $props);
                    if ($kept_time === null || $time === null || abs($kept_time - $time) > self::DATE_TOLERANCE) {
                        continue;
                    }
                    unset($files[$path]);
                    try {
                        $this->deleteSimilar($path, $props, $kept, $kept_props);
                    } catch (\Exception $e) {
                        $this->stats['errors']++;
                        $this->log('[' . $this->relative($path) . '] - Failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private function deleteSimilar(string $path, array $props, string $kept, array $kept_props): void {
        $this->log('[' . $this->relative($path) . '] - Same photo as "' . $this->relative($kept) . '" ('
                . self::formatSize($props) . ' vs ' . self::formatSize($kept_props) . ', taken '
                . gmdate('Y-m-d H:i', self::takenTimestamp($path, $props)) . ' vs ' . gmdate('Y-m-d H:i', self::takenTimestamp($kept, $kept_props)) . ')');
        $this->stats['similar_duplicates']++;
        if ($this->delete_duplicates === false) {
            return;
        }
        foreach ($this->albumsOf($path, $props) as $album) {
            $this->addToAlbum($album, $kept, $kept_props);
        }
        $this->log('[' . $this->relative($path) . '] - Deleting the smaller file');
        $this->delete($path);
        $this->unindex($path, $props);
    }

    static function formatSize(array $props): string {
        $size = (int) ($props['{DAV:}getcontentlength'] ?? 0);
        return $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : round($size / 1024) . ' KB';
    }

    /**
     * Logs the media files that share their original name with another file in the same directory, i.e. got a
     * "(n)" name because they are different files.
     */
    private function reportSameNames(): void {
        $groups = [];
        foreach ($this->target_by_size as $entries) {
            foreach ($entries as $path => $entry) {
                if (self::isMedia($entry['props'])) {
                    $groups[dirname($path) . '/' . self::originalName(basename($path))][$path] = $entry['props'];
                }
            }
        }
        $groups = array_filter($groups, fn(array $files) => count($files) > 1);
        if (count($groups) === 0) {
            return;
        }
        ksort($groups, SORT_NATURAL);

        IO::write('---');
        $this->log('Files with the same name that were not deduplicated (' . count($groups) . '), please check them:');
        foreach ($groups as $files) {
            // the original name first, then the "(n)" names
            uksort($files, fn(string $a, string $b) => [preg_match('/\(\d+\)\.[^.]*$/', $a), $a] <=> [preg_match('/\(\d+\)\.[^.]*$/', $b), $b]);
            $this->log('  ' . $this->relative(dirname(array_key_first($files))) . ': ' . implode(', ', array_map(
                    fn(string $path) => basename($path) . ' (' . self::formatSize($files[$path]) . ', taken '
                    . (self::takenTimestamp($path, $files[$path]) === null ? 'unknown' : gmdate('Y-m-d H:i', self::takenTimestamp($path, $files[$path]))) . ')',
                    array_keys($files)
            )));
        }
    }

    /**
     * The album of a file: the directory directly below the target it is in, unless that is a year directory.
     */
    private function folderAlbum(string $path): ?string {
        $parts = explode('/', substr($path, strlen($this->target_path) + 1));
        if (count($parts) < 2 || preg_match('/^\d{4}$/', $parts[0]) === 1) {
            return null;
        }
        return $parts[0];
    }

    private function filenameDate(string $path, array $props): ?int {
        $timestamp = FilenameDate::parse(basename($path));
        if ($timestamp === null) {
            return null;
        }
        $created = (int) ($props['{http://nextcloud.org/ns}creation_time'] ?? 0);
        $modified = strtotime($props['{DAV:}getlastmodified'] ?? '');
        $created_off = $created > 0 && abs($created - $timestamp) > self::DATE_TOLERANCE;
        $modified_off = $modified !== false && abs($modified - $timestamp) > self::DATE_TOLERANCE;
        if ($created_off === false && $modified_off === false) {
            return null;
        }

        $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
        if (self::hasExifDate($props) && is_numeric($original) && abs((int) $original - $timestamp) > self::DATE_TOLERANCE) {
            $this->log('[' . $this->relative($path) . '] - EXIF date ' . gmdate('Y-m-d', (int) $original) . ' differs from file name, left unchanged');
            return null;
        }
        $this->log('[' . $this->relative($path) . '] - ' . ($created_off ? 'Creation time ' . gmdate('Y-m-d', $created) : 'Modification time ' . gmdate('Y-m-d', $modified))
                . ' differs from file name date ' . gmdate('Y-m-d H:i:s', $timestamp));
        return $timestamp;
    }
}
