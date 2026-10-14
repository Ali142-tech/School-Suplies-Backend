<?php

namespace App\Services\Cms;

use App\Models\Vendor;
use App\Rules\PlainText;
use App\Rules\SafeUrl;
use Illuminate\Support\Facades\Storage;

/** Website settings stored in the existing store_settings structure (brand_color, banner_path and the settings JSON) plus vendors.logo_path. */
class SiteSettings
{
    public const HEX = '/^#[0-9a-fA-F]{6}$/';

    public static function rules(): array
    {
        $text = fn (int $max) => ['nullable', 'string', "max:$max", new PlainText];
        $link = ['required', 'array:label,url'];

        return [
            'brand_color' => ['nullable', 'regex:'.self::HEX],
            'settings' => ['nullable', 'array:description,accent_color,navigation,footer,seo'],
            'settings.description' => $text(2000),
            'settings.accent_color' => ['nullable', 'regex:'.self::HEX],
            'settings.navigation' => ['nullable', 'array', 'max:10'],
            'settings.navigation.*' => $link,
            'settings.navigation.*.label' => ['required', 'string', 'max:40', new PlainText],
            'settings.navigation.*.url' => ['required', 'string', new SafeUrl],
            'settings.footer' => ['nullable', 'array:text,links'],
            'settings.footer.text' => $text(500),
            'settings.footer.links' => ['nullable', 'array', 'max:10'],
            'settings.footer.links.*' => $link,
            'settings.footer.links.*.label' => ['required', 'string', 'max:40', new PlainText],
            'settings.footer.links.*.url' => ['required', 'string', new SafeUrl],
            'settings.seo' => ['nullable', 'array:title,description'],
            'settings.seo.title' => $text(70),
            'settings.seo.description' => $text(170),
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    /** Settings record plus resolved image URLs, for the vendor dashboard. */
    public static function withUrls(Vendor $vendor, $settings): array
    {
        $url = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;

        return [...$settings->toArray(), 'logo_url' => $url($vendor->fresh()->logo_path), 'banner_url' => $url($settings->banner_path)];
    }

    /** Public/preview shape: never includes internal ids or contact data the vendor did not publish on the site. */
    public static function present(Vendor $vendor): array
    {
        $settings = $vendor->settings;
        $extra = $settings?->settings ?? [];
        $url = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;

        return [
            'name' => $vendor->name,
            'logo_url' => $url($vendor->logo_path),
            'banner_url' => $url($settings?->banner_path),
            'brand_color' => $settings?->brand_color,
            'accent_color' => $extra['accent_color'] ?? null,
            'description' => $extra['description'] ?? $vendor->description,
            'navigation' => $extra['navigation'] ?? [],
            'footer' => ['text' => $extra['footer']['text'] ?? null, 'links' => $extra['footer']['links'] ?? []],
            'seo' => ['title' => $extra['seo']['title'] ?? null, 'description' => $extra['seo']['description'] ?? null],
        ];
    }
}
