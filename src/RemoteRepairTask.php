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
            private bool $folder_albums = true
    ) {
        if (in_array($job, self::JOBS, true) === false) {
            throw new \Exception('Unknown job "' . $job . '", use one of: ' . implode(', ', self::JOBS));
        }
        parent::__construct($client, $attempt, $target_path, $albums_base_path, $dry_run, $delete_duplicates);
        $this->exclude_paths = array_map(fn(string $path) => rtrim($path, '/'), $exclude_paths);
    }

    public function __invoke(): array {
        $this->indexTarget($this->exclude_paths);

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
            if (self::isMedia($props) === false) {
                continue;
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

        $this->log('Moved ' . $this->stats['moved'] . ' files, '
                . ($this->delete_duplicates ? 'deleted ' : 'found ') . $this->stats['duplicates'] . ' duplicates, '
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
