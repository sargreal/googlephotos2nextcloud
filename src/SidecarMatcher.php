<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Finds the Google Takeout JSON sidecar(s) belonging to a media file, using only file names.
 *
 * Handles the naming schemes seen in Takeouts:
 *  - IMG_1234.jpg.json / IMG_1234.jpg.supplemental-metadata.json (and truncated variants)
 *  - IMG_1234(1).jpg -> IMG_1234.jpg(1).json / IMG_1234.jpg.supplemental-metadata(1).json
 *  - edited copies (IMG_1234-edited.jpg) use the sidecar of the original
 *  - Live/Motion photo clips (IMG_1234.MP4) use the sidecar of their HEIC/JPG companion
 *  - long file names, where Google truncated the sidecar name
 */
class SidecarMatcher {

    const EDITED_SUFFIXES = ['-edited', '-bearbeitet', '-modifié', '-editado', '-modificato', '-bewerkt', '-edytowane'];
    const COMPANION_IMAGE_EXTENSIONS = ['HEIC', 'heic', 'JPG', 'jpg', 'JPEG', 'jpeg'];
    const MOTION_VIDEO_EXTENSIONS = ['MP4', 'MOV'];

    /**
     * @param string $media_filename
     * @param string[] $json_filenames json files in the same directory
     * @return string[] matching json filenames, best match first
     */
    static function candidates(string $media_filename, array $json_filenames): array {
        $candidates = self::direct($media_filename, $json_filenames);
        if (count($candidates) > 0) {
            return $candidates;
        }

        $extension = pathinfo($media_filename, PATHINFO_EXTENSION);
        $filename = pathinfo($media_filename, PATHINFO_FILENAME);

        foreach (self::EDITED_SUFFIXES as $edited_suffix) {
            if (str_ends_with($filename, $edited_suffix)) {
                $candidates = self::direct(substr($filename, 0, -strlen($edited_suffix)) . '.' . $extension, $json_filenames);
                if (count($candidates) > 0) {
                    return $candidates;
                }
            }
        }

        if (in_array(strtoupper($extension), self::MOTION_VIDEO_EXTENSIONS, true)) {
            foreach (self::COMPANION_IMAGE_EXTENSIONS as $companion_extension) {
                $candidates = self::direct($filename . '.' . $companion_extension, $json_filenames);
                if (count($candidates) > 0) {
                    return $candidates;
                }
            }
        }

        return [];
    }

    /**
     * Media files and their sidecars share the start of their name, so bucketing on it avoids comparing every
     * media file with every json file.
     */
    static function key(string $filename): string {
        return mb_substr(preg_split('/[.(]/', $filename, 2)[0], 0, 5);
    }

    /**
     * @param string[] $json_filenames
     * @return array<string, string[]> to be used with indexedCandidates()
     */
    static function index(array $json_filenames): array {
        $index = [];
        foreach (array_unique($json_filenames) as $json_filename) {
            $index[self::key($json_filename)][] = $json_filename;
        }
        return $index;
    }

    static function indexedCandidates(string $media_filename, array $index): array {
        $keys = [self::key($media_filename)];
        $extension = pathinfo($media_filename, PATHINFO_EXTENSION);
        $filename = pathinfo($media_filename, PATHINFO_FILENAME);
        foreach (self::EDITED_SUFFIXES as $edited_suffix) {
            if (str_ends_with($filename, $edited_suffix)) {
                $keys[] = self::key(substr($filename, 0, -strlen($edited_suffix)) . '.' . $extension);
            }
        }
        $json_filenames = [];
        foreach (array_unique($keys) as $key) {
            array_push($json_filenames, ...($index[$key] ?? []));
        }
        return self::candidates($media_filename, $json_filenames);
    }

    private static function direct(string $media_filename, array $json_filenames): array {
        $extension = pathinfo($media_filename, PATHINFO_EXTENSION);
        $filename = pathinfo($media_filename, PATHINFO_FILENAME);
        $increment = '';
        if (preg_match('/^(.*?)\s*(\(\d+\))$/', $filename, $matches) === 1) {
            $filename = $matches[1];
            $increment = $matches[2];
        }
        $original_filename = $filename . ($extension !== '' ? '.' . $extension : '');

        $exact = [];
        $truncated = [];
        foreach ($json_filenames as $json_filename) {
            if (str_ends_with($json_filename, '.json') === false) {
                continue;
            }
            $json_stem = substr($json_filename, 0, -strlen('.json'));
            if ($increment !== '' && ($json_stem === $media_filename || str_starts_with($json_stem, $media_filename . '.'))) {
                // e.g. IMG(1).jpg.json
                $exact[] = $json_filename;
                continue;
            }

            $json_increment = '';
            if (preg_match('/^(.*?)(\(\d+\))$/', $json_stem, $matches) === 1) {
                $json_stem = $matches[1];
                $json_increment = $matches[2];
            }
            if ($json_increment !== $increment) {
                continue;
            }

            if ($json_stem === $original_filename || str_starts_with($json_stem, $original_filename . '.')) {
                $exact[] = $json_filename;
            } elseif (strlen($json_stem) >= 30 && str_starts_with($original_filename, rtrim($json_stem, '.'))) {
                // Google truncates long sidecar names (to 51 characters including '.json')
                $truncated[] = $json_filename;
            }
        }

        return count($exact) > 0 ? $exact : $truncated;
    }

    /**
     * Extracts the taken time from decoded sidecar contents.
     */
    static function timestamp(mixed $metadata): ?int {
        if (is_array($metadata) === false) {
            return null;
        }
        foreach (['photoTakenTime', 'creationTime'] as $key) {
            if (isset($metadata[$key]['timestamp']) && is_numeric($metadata[$key]['timestamp'])) {
                return (int) $metadata[$key]['timestamp'];
            }
        }
        return null;
    }
}
