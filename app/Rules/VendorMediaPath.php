<?php

namespace App\Rules;

use App\Models\Vendor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;

/** An image reference must be a file this vendor uploaded through the CMS media endpoint. */
class VendorMediaPath implements ValidationRule
{
    public function __construct(private readonly Vendor $vendor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $prefix = 'cms/'.$this->vendor->id.'/';
        if (! is_string($value) || ! preg_match('#^'.preg_quote($prefix, '#').'[A-Za-z0-9_-]{16,64}\.(jpg|jpeg|png|webp)$#', $value) || ! Storage::disk('public')->exists($value)) {
            $fail('The :attribute must be an image uploaded to your own media library.');
        }
    }
}
