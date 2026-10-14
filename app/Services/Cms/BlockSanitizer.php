<?php

namespace App\Services\Cms;

use App\Models\Vendor;
use App\Rules\PlainText;
use App\Rules\RichText;
use App\Rules\SafeUrl;
use App\Rules\VendorMediaPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises vendor-supplied page blocks. Only whitelisted keys of whitelisted block types
 * survive; nothing here is ever executed or rendered as HTML.
 */
class BlockSanitizer
{
    public const MAX_BLOCKS = 40;

    public const ICONS = ['package', 'printer', 'truck', 'shield', 'heart', 'star', 'book', 'clock'];

    public const TYPES = ['hero', 'heading_text', 'image', 'image_text', 'features', 'cta', 'contact'];

    public function sanitize(mixed $blocks, Vendor $vendor): array
    {
        if ($blocks === null) {
            return [];
        }
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::withMessages(['blocks' => 'Blocks must be a list.']);
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages(['blocks' => 'A page can have at most '.self::MAX_BLOCKS.' blocks.']);
        }

        $errors = [];
        $clean = [];
        $seen = [];
        foreach ($blocks as $index => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                $errors["blocks.$index.type"] = ['Unsupported block type.'];

                continue;
            }
            $validator = Validator::make($block, $this->rules($type, $vendor));
            $validator->after(function ($validator) use ($type, $block) {
                $this->requireContent($validator, $type, $block);
            });
            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $key => $messages) {
                    $errors["blocks.$index.$key"] = $messages;
                }

                continue;
            }
            $normalised = $this->normalise($validator->validated());
            $id = $normalised['id'] ?? null;
            if (! $id || isset($seen[$id])) {
                $id = Str::lower(Str::random(12));
            }
            $seen[$id] = true;
            $clean[] = ['id' => $id, 'type' => $type, ...Arr::except($normalised, ['id', 'type'])];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** Adds resolved image URLs for output (public site and preview). */
    public function present(array $blocks): array
    {
        return array_map(function (array $block) {
            if (! empty($block['image'])) {
                $block['image_url'] = Storage::disk('public')->url($block['image']);
            }

            return $block;
        }, $blocks);
    }

    private function rules(string $type, Vendor $vendor): array
    {
        $text = fn (int $max, bool $required = false) => [$required ? 'required' : 'nullable', 'string', "max:$max", new PlainText];
        $rich = fn (int $max) => ['nullable', 'string', "max:$max", new RichText];
        $media = fn (bool $required) => [$required ? 'required' : 'nullable', 'string', new VendorMediaPath($vendor)];
        $cta = ['cta_label' => [...$text(40), 'required_with:cta_url'], 'cta_url' => ['nullable', 'string', new SafeUrl, 'required_with:cta_label']];
        $base = ['id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'], 'type' => ['required']];

        return $base + match ($type) {
            'hero' => ['heading' => $text(120, true), 'subheading' => $text(300), 'image' => $media(false), 'align' => ['nullable', 'in:left,center']] + $cta,
            'heading_text' => ['heading' => $text(160), 'level' => ['nullable', 'integer', 'in:2,3'], 'body' => $rich(10000)],
            'image' => ['image' => $media(true), 'alt' => $text(200, true), 'caption' => $text(200)],
            'image_text' => ['image' => $media(true), 'alt' => $text(200, true), 'heading' => $text(160), 'body' => $rich(5000), 'image_position' => ['nullable', 'in:left,right']] + $cta,
            'features' => ['heading' => $text(160), 'items' => ['required', 'array', 'min:1', 'max:8'], 'items.*.title' => $text(80, true), 'items.*.description' => $text(300), 'items.*.icon' => ['nullable', 'in:'.implode(',', self::ICONS)]],
            'cta' => ['heading' => $text(160, true), 'text' => $text(300), 'button_label' => $text(40, true), 'button_url' => ['required', 'string', new SafeUrl], 'style' => ['nullable', 'in:primary,secondary']],
            'contact' => ['heading' => $text(160), 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'regex:/^[0-9+()\-\s.]{5,32}$/'], 'address' => $text(300), 'hours' => $text(200)],
        };
    }

    private function requireContent($validator, string $type, array $block): void
    {
        $filled = fn (string ...$keys) => collect($keys)->contains(fn ($k) => filled($block[$k] ?? null));
        if ($type === 'heading_text' && ! $filled('heading', 'body')) {
            $validator->errors()->add('heading', 'Add a heading or some text.');
        }
        if ($type === 'contact' && ! $filled('email', 'phone', 'address', 'hours')) {
            $validator->errors()->add('email', 'Add at least one contact detail.');
        }
    }

    private function normalise(array $data): array
    {
        $walk = function ($value) use (&$walk) {
            if (is_array($value)) {
                $value = array_map($walk, $value);

                return array_is_list($value) ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== [])) : array_filter($value, fn ($v) => $v !== null && $v !== '' && $v !== []);
            }
            if (is_string($value)) {
                return trim(str_replace(["\r\n", "\r"], "\n", $value));
            }

            return $value;
        };
        $data = $walk($data);
        if (isset($data['level'])) {
            $data['level'] = (int) $data['level'];
        }

        return $data;
    }
}
