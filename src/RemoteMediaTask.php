<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Shared WebDAV operations for organising media files in <target>/Y/m.
 *
 * Nextcloud Photos sorts on a date it only calculates when a file is created (from EXIF, else from the
 * modification time), and a MOVE keeps the old value. So when that date is wrong, files are copied server-side
 * and the original is deleted (or replaced) instead of moved; albums are carried over to the copy.
 *
 * Internally all paths are decoded DAV paths (e.g. /remote.php/dav/files/user/Photos/2020/01),
 * they are only encoded when sent to the server.
 */
abstract class RemoteMediaTask {

    const TEMPORARY_PREFIX = '.gp2nc-tmp-';
    const DATE_TOLERANCE = 86400; // EXIF dates are local time, sidecar dates are UTC
    const PROPERTIES = [
        '{DAV:}getcontentlength',
        '{DAV:}getcontenttype',
        '{DAV:}getlastmodified',
        '{DAV:}resourcetype',
        '{http://owncloud.org/ns}checksums',
        '{http://owncloud.org/ns}fileid',
        '{http://nextcloud.org/ns}creation_time',
        '{http://nextcloud.org/ns}metadata-photos-original_date_time',
        '{http://nextcloud.org/ns}metadata-photos-exif',
    ];

    /** @var array<int, array<string, array{props: array, real: string}>> size => [path => entry] */
    protected array $target_by_size = [];
    /** @var array<string, string> real path => md5 */
    protected array $hashes = [];
    /** @var array<string, array<string, true>>|null album name => [fileid => true] */
    protected ?array $albums = null;
    protected array $created_directories = [];
    /** @var array<string, true> files whose date repair was interrupted */
    protected array $interrupted_repairs = [];
    protected array $stats = ['moved' => 0, 'duplicates' => 0, 'album_additions' => 0, 'dates_repaired' => 0, 'errors' => 0];

    public function __construct(
            protected \Sabre\DAV\Client $client,
            protected Attempt $attempt,
            protected string $target_path,
            protected string $albums_base_path,
            protected bool $dry_run,
            protected bool $delete_duplicates
    ) {
        $this->target_path = rtrim($target_path, '/');
        $this->albums_base_path = rtrim($albums_base_path, '/');
    }

    static function encode(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    static function isInside(string $path, string $directory): bool {
        return $path === $directory || str_starts_with($path, $directory . '/');
    }

    protected function log(string $message): void {
        IO::write(($this->dry_run ? '[dry-run] ' : '') . $message);
    }

    /**
     * @return string[] directories that paths are logged relative to
     */
    protected function relativeBases(): array {
        return [$this->target_path];
    }

    protected function relative(string $path): string {
        foreach ($this->relativeBases() as $base) {
            if (self::isInside($path, $base)) {
                return basename($base) . substr($path, strlen($base));
            }
        }
        return $path;
    }

    static function isCollection(array $props): bool {
        $type = $props['{DAV:}resourcetype'] ?? null;
        return $type instanceof \Sabre\DAV\Xml\Property\ResourceType && $type->is('{DAV:}collection');
    }

    /**
     * @return array<string, array> decoded path => properties, without the directory itself
     */
    protected function list(string $directory): array {
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

    protected function walk(string $directory, callable $callback, array $excludes = []): void {
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

    /**
     * Indexes all files below the target (creating it when missing), to find duplicates.
     *
     * @param string[] $excludes directories to skip
     */
    protected function indexTarget(array $excludes = []): void {
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
            }, $excludes);
        }
        $this->log('Indexed ' . $indexed . ' existing files');
    }

    protected function index(string $path, array $props, string $real): void {
        $this->target_by_size[(int) $props['{DAV:}getcontentlength']][$path] = ['props' => $props, 'real' => $real];
    }

    protected function unindex(string $path, array $props): void {
        unset($this->target_by_size[(int) $props['{DAV:}getcontentlength']][$path]);
    }

    static function isMedia(array $props): bool {
        $type = $props['{DAV:}getcontenttype'] ?? '';
        return str_starts_with($type, 'image/') || str_starts_with($type, 'video/');
    }

    /**
     * Whether the file has an EXIF DateTimeOriginal, which Nextcloud Photos uses as its date.
     */
    static function hasExifDate(array $props): bool {
        foreach ((array) ($props['{http://nextcloud.org/ns}metadata-photos-exif'] ?? []) as $element) {
            if (is_array($element) && str_ends_with((string) ($element['name'] ?? ''), 'DateTimeOriginal') && trim((string) ($element['value'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The year/month directory the time belongs in.
     */
    protected function directoryFor(int $timestamp): string {
        return $this->target_path . gmdate('/Y/m', $timestamp);
    }

    /**
     * Moves a file in the target to the year/month directory of the given time and sets its dates, keeping its
     * albums. When an identical file already exists in the target, the file is deleted instead and its albums
     * are added to the remaining file.
     */
    protected function relocate(string $path, array $props, int $timestamp): void {
        $fileid = (string) ($props['{http://owncloud.org/ns}fileid'] ?? '');
        $duplicate = $this->findDuplicate($path, $props);
        if ($duplicate !== null) {
            $this->log('[' . $this->relative($path) . '] - Identical to "' . $this->relative($duplicate) . '"');
            $this->stats['duplicates']++;
            if ($this->delete_duplicates) {
                $remaining = $this->target_by_size[(int) $props['{DAV:}getcontentlength']][$duplicate]['props'];
                foreach ($this->albumsContaining($fileid) as $album) {
                    $this->addToAlbum($album, $duplicate, $remaining);
                }
                $this->log('[' . $this->relative($path) . '] - Deleting duplicate');
                $this->delete($path);
                $this->unindex($path, $props);
            }
            return;
        }

        $directory = $this->directoryFor($timestamp);
        if (dirname($path) === $directory) {
            if (self::photosDateMatches($props, $timestamp)) {
                $this->log('[' . $this->relative($path) . '] - Setting dates to ' . gmdate('Y-m-d H:i:s', $timestamp));
                $this->setDates($path, $timestamp);
                $this->stats['dates_repaired']++;
            } else {
                $this->repairDate($path, $props, $timestamp);
            }
            return;
        }

        $this->ensureDirectory($directory);
        $this->setDates($path, $timestamp);
        if (self::photosDateMatches($props, $timestamp)) {
            $destination = $this->move($path, $directory);
            $this->logRename($path, $props, $destination);
            $this->log('[' . $this->relative($path) . '] - Moved to "' . $this->relative($destination) . '"');
        } else {
            // a copy makes Nextcloud Photos calculate its date again, a move does not
            $albums = $this->albumsContaining($fileid);
            $destination = $this->move($path, $directory, 'COPY');
            $this->logRename($path, $props, $destination);
            $this->log('[' . $this->relative($path) . '] - Copied to "' . $this->relative($destination) . '" (to update the date in Nextcloud Photos)');
            $new_props = $this->dry_run ? $props : $this->properties($destination);
            foreach ($albums as $album) {
                $this->addToAlbum($album, $destination, $new_props);
            }
            $this->delete($path);
            $props = $new_props;
        }
        $this->unindex($path, $props);
        if (isset($this->hashes[$path])) {
            $this->hashes[$destination] = $this->hashes[$path];
        }
        $this->index($destination, $props, $this->dry_run ? $path : $destination);
        $this->stats['moved']++;
    }

    protected function hash(string $real, array $props): string {
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

    protected function findDuplicate(string $path, array $props): ?string {
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

    protected function exists(string $path): bool {
        $attempt = $this->attempt;
        return $attempt('request', 'HEAD', self::encode($path))['statusCode'] !== 404;
    }

    protected function mkdirs(string $path): void {
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

    protected function properties(string $path): array {
        $attempt = $this->attempt;
        return $attempt('propFind', self::encode($path), self::PROPERTIES, 0);
    }

    protected function delete(string $path): void {
        if ($this->dry_run) {
            return;
        }
        $attempt = $this->attempt;
        $response = $attempt('request', 'DELETE', self::encode($path));
        if ($response['statusCode'] < 200 || $response['statusCode'] > 299) {
            throw new \Exception('Could not delete "' . $path . '" (' . $response['statusCode'] . ')');
        }
    }

    protected function setDates(string $path, int $timestamp): void {
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

    protected function ensureDirectory(string $directory): void {
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
    protected function albumsContaining(string $fileid): array {
        $this->albumFileIds(null);
        return array_values(array_filter(array_keys($this->albums), fn($album) => isset($this->albumFileIds((string) $album)[$fileid])));
    }

    protected function albumFileIds(?string $album): array {
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

    protected function addToAlbum(string $album, string $path, array $props): void {
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
    protected function move(string $path, string $directory, string $method = 'MOVE'): string {
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

    /**
     * Explains why a file got a "(n)" name: a different file with its name is already there.
     */
    protected function logRename(string $path, array $props, string $destination): void {
        $taken = dirname($destination) . '/' . basename($path);
        if (basename($destination) === basename($path)) {
            return;
        }
        $size = (int) ($props['{DAV:}getcontentlength'] ?? 0);
        foreach ($this->target_by_size as $existing_size => $entries) {
            if (isset($entries[$taken])) {
                $this->log('[' . $this->relative($path) . '] - "' . $this->relative($taken) . '" is a different file ('
                        . ($existing_size === $size ? 'same size, different content' : 'size ' . $size . ' vs ' . $existing_size . ' bytes') . ')');
                return;
            }
        }
        $this->log('[' . $this->relative($path) . '] - "' . $this->relative($taken) . '" already exists');
    }

    protected function isIndexed(string $path): bool {
        foreach ($this->target_by_size as $entries) {
            if (isset($entries[$path])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Copying makes Nextcloud Photos calculate the date again; the copy replaces the original, so it gets a
     * new file id and is added to the albums of the original again.
     */
    protected function repairDate(string $path, array $props, int $timestamp): void {
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
}
