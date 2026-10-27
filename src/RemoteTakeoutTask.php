<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Imports a Google Takeout that has already been extracted inside Nextcloud.
 *
 * Media files are moved (not re-uploaded) into <target>/Y/m, files whose contents already exist in
 * the target are deleted (they end up in the Nextcloud trash bin), and files from album directories
 * are added to the corresponding Nextcloud Photos album.
 *
 * Internally all paths are decoded DAV paths (e.g. /remote.php/dav/files/user/Takeout/Photos from 2020),
 * they are only encoded when sent to the server.
 */
class RemoteTakeoutTask {

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
    private array $stats = ['moved' => 0, 'duplicates' => 0, 'album_additions' => 0, 'errors' => 0];

    public function __construct(
            private \Sabre\DAV\Client $client,
            private Attempt $attempt,
            private string $takeout_path,
            private string $target_path,
            private string $albums_base_path,
            private bool $dry_run,
            private bool $delete_duplicates
    ) {
        $this->takeout_path = rtrim($takeout_path, '/');
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
        foreach ([$this->takeout_path, $this->target_path] as $base) {
            if (self::isInside($path, $base)) {
                return basename($base) . substr($path, strlen($base));
            }
        }
        return $path;
    }

    public function __invoke(): array {
        if (self::isInside($this->target_path, $this->takeout_path)) {
            throw new \Exception('Target directory must not be inside the takeout directory');
        }

        $this->log('Indexing existing files in ' . $this->target_path);
        $indexed = 0;
        $this->walk($this->target_path, function (string $directory, array $children) use (&$indexed) {
            foreach ($children as $path => $props) {
                if (self::isCollection($props) === false && isset($props['{DAV:}getcontentlength'])) {
                    $this->index($path, $props, $path);
                    $indexed++;
                }
            }
        }, $this->takeout_path);
        $this->log('Indexed ' . $indexed . ' existing files');

        $this->log('Walking takeout in ' . $this->takeout_path);
        $this->walk($this->takeout_path, fn(string $directory, array $children) => $this->processDirectory($directory, $children));

        $this->log('Moved ' . $this->stats['moved'] . ' files, '
                . ($this->delete_duplicates ? 'deleted ' : 'found ') . $this->stats['duplicates'] . ' duplicates, '
                . 'made ' . $this->stats['album_additions'] . ' album additions, '
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

    private function walk(string $directory, callable $callback, ?string $exclude = null): void {
        $children = $this->list($directory);
        $callback($directory, $children);
        foreach ($children as $path => $props) {
            if (self::isCollection($props) && ($exclude === null || self::isInside($path, $exclude) === false)) {
                $this->walk($path, $callback, $exclude);
            }
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

    private function albumTitle(string $directory, array $json_paths): ?string {
        foreach ($json_paths as $json_path) {
            if (in_array(mb_strtolower(basename($json_path)), self::ALBUM_METADATA_FILENAMES, true) === false) {
                continue;
            }
            $metadata = $this->fetchJson($json_path);
            if (is_array($metadata) === false || array_key_exists('title', $metadata) === false) {
                continue;
            }
            return empty($metadata['title']) ? basename($directory) : str_replace('/', '-', $metadata['title']);
        }
        return null;
    }

    private function takenTime(string $path, array $props, array $json_paths): \DateTimeImmutable {
        $json_by_name = [];
        foreach ($json_paths as $json_path) {
            $json_by_name[basename($json_path)] = $json_path;
        }
        foreach (SidecarMatcher::candidates(basename($path), array_keys($json_by_name)) as $json_name) {
            $timestamp = SidecarMatcher::timestamp($this->fetchJson($json_by_name[$json_name]));
            if ($timestamp !== null) {
                $this->log('[' . basename($path) . '] - Taken time from ' . $json_name);
                return new \DateTimeImmutable('@' . $timestamp);
            }
        }

        $original = $props['{http://nextcloud.org/ns}metadata-photos-original_date_time'] ?? null;
        if (is_numeric($original)) {
            $this->log('[' . basename($path) . '] - No sidecar metadata, using taken time extracted by Nextcloud');
            return new \DateTimeImmutable('@' . $original);
        }

        $this->log('[' . basename($path) . '] - No sidecar metadata, falling back to last modified time');
        return new \DateTimeImmutable($props['{DAV:}getlastmodified'] ?? 'now');
    }

    private function ensureDirectory(string $directory): void {
        if ($this->dry_run || isset($this->created_directories[$directory])) {
            return;
        }
        $relative = substr($directory, strlen($this->target_path));
        RemoteDirectory::create($this->attempt, self::encode($this->target_path), self::encode($relative));
        $this->created_directories[$directory] = true;
    }

    private function albumFileIds(string $album): array {
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
     * Moves the file without overwriting, picking "name(n).ext" when the name is taken.
     */
    private function move(string $path, string $directory): string {
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
            $response = $attempt('request', 'MOVE', self::encode($path), headers: [
                'Destination' => $this->client->getAbsoluteUrl(self::encode($destination)),
                'Overwrite' => 'F',
            ]);
            if ($response['statusCode'] === 201 || $response['statusCode'] === 204) {
                return $destination;
            } elseif ($response['statusCode'] !== 412) {
                throw new \Exception('Could not move to "' . $destination . '" (' . $response['statusCode'] . ')');
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

    private function processDirectory(string $directory, array $children): void {
        $json_paths = [];
        $media = [];
        foreach ($children as $path => $props) {
            if (self::isCollection($props)) {
                continue;
            } elseif (str_ends_with(strtolower($path), '.json')) {
                $json_paths[] = $path;
            } elseif (str_starts_with(basename($path), '.') === false) {
                $media[$path] = $props;
            }
        }
        if (count($media) === 0) {
            return;
        }

        IO::write('---');
        $album = $this->albumTitle($directory, $json_paths);
        $this->log('Processing "' . $this->relative($directory) . '" with ' . count($media) . ' media files' . ($album !== null ? ', album "' . $album . '"' : ''));

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
                        if ($this->dry_run === false) {
                            $attempt = $this->attempt;
                            $response = $attempt('request', 'DELETE', self::encode($path));
                            if ($response['statusCode'] < 200 || $response['statusCode'] > 299) {
                                throw new \Exception('Could not delete (' . $response['statusCode'] . ')');
                            }
                        }
                    }
                    continue;
                }

                $target_directory = $this->target_path . $this->takenTime($path, $props, $json_paths)->format('/Y/m');
                if ($album !== null) {
                    // file ids survive a move, so adding before moving keeps album membership even if the move fails
                    $this->addToAlbum($album, $path, $props);
                }
                $this->ensureDirectory($target_directory);
                $destination = $this->move($path, $target_directory);
                $this->log('[' . $name . '] - ' . $index . '/' . count($media) . ' moved to "' . $this->relative($destination) . '"');
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
