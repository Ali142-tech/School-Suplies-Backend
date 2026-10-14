<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restricted markup: **bold**, *italic*, [text](url), "- " bullet lists and "1. " numbered lists.
 * It is stored and rendered as text by React (never as HTML), so raw HTML is rejected outright and every link target is checked.
 */
class RichText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }
        if (PlainText::hasMarkup($value) || PlainText::hasControlChars(str_replace("\n", '', $value))) {
            $fail('The :attribute may not contain HTML or control characters.');

            return;
        }
        if (preg_match_all('/\[[^\]]*\]\(([^)]*)\)/', $value, $links)) {
            foreach ($links[1] as $url) {
                if (! SafeUrl::isSafe(trim($url))) {
                    $fail('The :attribute contains a link that is not allowed.');

                    return;
                }
            }
        }
    }
}
