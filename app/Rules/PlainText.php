<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Text that is only ever rendered as text: no HTML-like markup and no control characters. */
class PlainText implements ValidationRule
{
    public static function hasMarkup(string $value): bool
    {
        return (bool) preg_match('/<\s*[a-z!\/?]/i', $value);
    }

    public static function hasControlChars(string $value): bool
    {
        return (bool) preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }
        if (self::hasMarkup($value) || self::hasControlChars($value)) {
            $fail('The :attribute may not contain HTML or control characters.');
        }
    }
}
