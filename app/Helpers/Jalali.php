<?php

namespace App\Helpers;

use DateTimeInterface;

class Jalali
{
    /**
     * Convert Gregorian date/datetime to Jalali formatted string (e.g. 1405/07/26).
     */
    public static function fromDateTime(?DateTimeInterface $date, string $format = 'Y/m/d'): ?string
    {
        if (!$date) {
            return null;
        }

        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('m');
        $gd = (int) $date->format('d');

        [$jy, $jm, $jd] = self::toJalali($gy, $gm, $gd);

        return str_replace(
            ['Y', 'm', 'd', 'H', 'i', 's'],
            [
                sprintf('%04d', $jy),
                sprintf('%02d', $jm),
                sprintf('%02d', $jd),
                $date->format('H'),
                $date->format('i'),
                $date->format('s'),
            ],
            $format
        );
    }

    /**
     * Standard Gregorian to Jalali conversion algorithm.
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + ((int) (($gy2 + 3) / 4)) - ((int) (($gy2 + 99) / 100)) + ((int) (($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * ((int) ($days / 12053)));
        $days %= 12053;
        $jy += 4 * ((int) ($days / 1461));
        $days %= 1461;

        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + ((int) ($days / 31));
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + ((int) (($days - 186) / 30));
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}

