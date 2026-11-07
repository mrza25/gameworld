<?php

namespace App\Http\Middleware;

use App\Helpers\PersianText;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Converts Arabic letters in everything customers and admins send, so searches
 * and stored text only ever use the Persian forms.
 */
class NormalizePersianInput extends TransformsRequest
{
    /**
     * @var list<string>
     */
    private const EXCEPT = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
    ];

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, self::EXCEPT, true)) {
            return $value;
        }

        return PersianText::normalizeLetters($value);
    }
}
