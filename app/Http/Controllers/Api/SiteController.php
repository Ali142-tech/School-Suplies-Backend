<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cms\BlockSanitizer;
use App\Services\Cms\SiteSettings;
use Illuminate\Http\Request;

/**
 * Public vendor website. The vendor is resolved by the trusted store.tenant middleware
 * (verified, active domain of an active vendor) and only published snapshots are ever returned.
 */
class SiteController extends Controller
{
    public function __construct(private readonly BlockSanitizer $blocks) {}

    public function show(Request $request)
    {
        $vendor = $request->attributes->get('tenant_vendor')->loadMissing('settings');
        // Titles come from the published snapshot: the live title column holds unpublished draft edits.
        $pages = $vendor->pages()->where('status', 'published')->whereNotNull('published_snapshot')->orderBy('id')->get(['slug', 'kind', 'published_snapshot']);

        return response()->json(['data' => SiteSettings::present($vendor) + ['pages' => $pages->map(fn ($p) => ['slug' => $p->slug, 'kind' => $p->kind, 'title' => $p->published_snapshot['title'] ?? $p->slug, 'path' => $p->kind === 'home' ? '/' : '/'.$p->slug])->all()]]);
    }

    public function page(Request $request, string $slug)
    {
        abort_if(in_array($slug, config('platform.reserved_page_slugs'), true), 404);
        $vendor = $request->attributes->get('tenant_vendor');
        $page = $vendor->pages()->where('status', 'published')->where('slug', $slug)->whereNotNull('published_snapshot')->firstOrFail();
        $content = $page->published_snapshot;

        return response()->json(['data' => [
            'slug' => $page->slug, 'kind' => $page->kind, 'title' => $content['title'], 'seo_title' => $content['seo_title'] ?? null,
            'meta_description' => $content['meta_description'] ?? null, 'blocks' => $this->blocks->present($content['blocks'] ?? []), 'published_at' => $page->published_at,
        ]]);
    }
}
