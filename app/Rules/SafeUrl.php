<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Allows https/http links, mailto:, tel:, site-relative paths and #anchors. Everything else (javascript:, data:, //host, userinfo URLs) is rejected. */
class SafeUrl implements ValidationRule
{
    public static function isSafe(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F\\\\<>"\']/', $url)) {
            return false;
        }
        if (preg_match('/^#[A-Za-z0-9_-]+$/', $url) || preg_match('#^/(?!/)[^\s]*$#', $url)) {
            return true;
        }
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', $url)) {
            return true;
        }
        if (preg_match('/^tel:\+?[0-9()\-.]{5,32}$/', $url)) {
            return true;
        }
        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);

            return $parts !== false && ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']);
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isSafe(trim($value))) {
            $fail('The :attribute must be an https link, mailto:, tel:, a relative path or an anchor.');
        }
    }
}
