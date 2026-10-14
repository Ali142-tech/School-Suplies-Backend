<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPage extends Model
{
    public const KIND_CUSTOM = 'custom';

    /** Editable default pages created for every approved vendor. Their slugs are fixed; they can be unpublished but not deleted. */
    public const DEFAULTS = [
        'home' => ['slug' => 'home', 'title' => 'Home'],
        'about' => ['slug' => 'about-us', 'title' => 'About Us'],
        'contact' => ['slug' => 'contact', 'title' => 'Contact'],
        'services' => ['slug' => 'services', 'title' => 'Services'],
    ];

    /** Mass assignment is limited to content fields; vendor_id, kind and status are always set server-side. */
    protected $fillable = ['title', 'slug', 'seo_title', 'meta_description', 'draft_blocks'];

    protected $casts = ['draft_blocks' => 'array', 'published_snapshot' => 'array', 'published_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDefault(): bool
    {
        return $this->kind !== self::KIND_CUSTOM;
    }

    /** The content a visitor would see if this draft were published right now. */
    public function draftContent(): array
    {
        return ['title' => $this->title, 'seo_title' => $this->seo_title, 'meta_description' => $this->meta_description, 'blocks' => $this->draft_blocks ?? []];
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->status === 'published' && $this->published_snapshot !== $this->draftContent();
    }

    public function publicPath(): string
    {
        return $this->kind === 'home' ? '/' : '/'.$this->slug;
    }
}
