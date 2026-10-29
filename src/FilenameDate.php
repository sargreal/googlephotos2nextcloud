<?php

namespace Rikmeijer\Googlephotos2nextcloud;

/**
 * Reads the taken time from file names like IMG_20190504_142512.jpg, IMG-20190504-WA0001.jpg (WhatsApp),
 * PXL_20190504_142512345.jpg, VID_20190504_142512.mp4, Screenshot_2019-05-04-14-25-12.png or 20190504_142512.jpg.
 *
 * The time in a file name is local time, it is interpreted in the default timezone (set TZ, e.g. Europe/Berlin).
 * Without a time, noon is used, so the date stays the same in any timezone.
 */
class FilenameDate {

    const PATTERN = '/(?<![0-9])((?:19|20)[0-9]{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12][0-9]|3[01])'
            . '(?:[-_ T.]?([01][0-9]|2[0-3])[-_.:h]?([0-5][0-9])(?:[-_.:m]?([0-5][0-9]))?[0-9]{0,3})?(?![0-9])/';

    static function parse(string $filename): ?int {
        if (preg_match(self::PATTERN, pathinfo($filename, PATHINFO_FILENAME), $matches) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        if ($year < 1990 || checkdate($month, $day, $year) === false) {
            return null;
        }
        $has_time = isset($matches[4]) && $matches[4] !== '';
        $date = (new \DateTimeImmutable())->setDate($year, $month, $day)->setTime(
                $has_time ? (int) $matches[4] : 12,
                $has_time ? (int) $matches[5] : 0,
                $has_time && isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : 0
        );
        return $date->getTimestamp() > time() ? null : $date->getTimestamp();
    }
}
