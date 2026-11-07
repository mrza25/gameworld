<?php

namespace App\Helpers;

class PersianText
{
    private const ARABIC_LETTERS = [
        'ي' => 'ی',
        'ى' => 'ی',
        'ك' => 'ک',
    ];

    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Arabic ي and ك look like Persian ی and ک but never match them in a search.
     */
    public static function normalizeLetters(string $text): string
    {
        return strtr($text, self::ARABIC_LETTERS);
    }

    public static function latinDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }
}
