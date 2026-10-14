<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores vendor-uploaded images on the public disk only after proving they really are images.
 *
 * Laravel's `image`/`mimes` rules only inspect the header, so a file with a valid header and a corrupt or hostile body passes.
 * Here the file is fully decoded (any decoder warning is a rejection) and re-encoded: this removes appended payloads and
 * metadata such as EXIF/GPS, and the stored file always gets a random server-generated name and extension.
 */
class ImageStore
{
    /** Decoding allocates roughly 5 bytes per pixel, so cap the pixel count independently of the file size. */
    public const MAX_PIXELS = 16_000_000;

    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public function store(UploadedFile $file, string $directory, string $field = 'image'): string
    {
        $fail = fn (string $message = 'The file is not a valid, readable image.') => throw ValidationException::withMessages([$field => $message]);

        $path = $file->getRealPath();
        $info = $path ? @getimagesize($path) : false;
        if (! $info || ! isset(self::TYPES[$info[2]])) {
            $fail();
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            $fail('The image dimensions are too large.');
        }

        $raw = (string) file_get_contents($path);
        if (! $this->isComplete($raw, $info[2])) {
            $fail('The image file is incomplete or damaged.');
        }

        $warnings = 0;
        set_error_handler(function (int $severity) use (&$warnings) {
            // Warnings from the decoder mean a damaged file; calls deliberately silenced with @ (EXIF probing) do not count.
            if (error_reporting() & $severity) {
                $warnings++;
            }

            return true;
        });
        try {
            $image = imagecreatefromstring($raw);
            $image = $image ? $this->orient($image, $path, $info[2]) : false;
            $encoded = $image ? $this->encode($image, $info[2]) : null;
        } finally {
            restore_error_handler();
        }
        if (! $image || $warnings > 0 || ! $encoded) {
            $fail();
        }

        $name = Str::random(40).'.'.self::TYPES[$info[2]];
        Storage::disk('public')->put($directory.'/'.$name, $encoded);

        return $directory.'/'.$name;
    }

    /**
     * The decoder alone is not enough: GD silently decodes a truncated JPEG (grey fill, no warning).
     * So each format must also show its end-of-data marker. Bytes appended after a PNG are tolerated because re-encoding discards them.
     */
    private function isComplete(string $raw, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => str_ends_with(rtrim($raw, "\0"), "\xFF\xD9"),
            IMAGETYPE_PNG => str_contains($raw, "IEND\xAE\x42\x60\x82"),
            IMAGETYPE_WEBP => strlen($raw) >= 12 && unpack('V', substr($raw, 4, 4))[1] + 8 <= strlen($raw),
            default => false,
        };
    }

    /** Re-encoding drops EXIF, so honour the orientation tag first or phone photos would end up sideways. */
    private function orient(\GdImage $image, string $path, int $type): \GdImage|false
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = @exif_read_data($path)['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle ? imagerotate($image, $angle, 0) : $image;
    }

    private function encode(\GdImage $image, int $type): ?string
    {
        ob_start();
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, 88),
            IMAGETYPE_WEBP => imagewebp($image, null, 85),
            default => (function () use ($image) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image);
            })(),
        };
        $data = ob_get_clean();

        return $data === false || $data === '' ? null : $data;
    }
}
