<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorPage;
use App\Rules\PlainText;
use App\Services\Cms\BlockSanitizer;
use App\Services\ImageStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Vendor CMS pages. The vendor always comes from the route-bound membership check (ResolveVendor),
 * and every page is looked up through $vendor->pages(), so another vendor's page id is a 404.
 */
class VendorPageController extends Controller
{
    public function __construct(private readonly BlockSanitizer $blocks) {}

    private function vendor(Request $request): Vendor
    {
        return $request->attributes->get('vendor');
    }

    private function page(Request $request, int|string $page): VendorPage
    {
        return $this->vendor($request)->pages()->findOrFail($page);
    }

    private function summary(VendorPage $page): array
    {
        return [
            'id' => $page->id, 'slug' => $page->slug, 'kind' => $page->kind, 'title' => $page->title, 'status' => $page->status,
            'is_default' => $page->isDefault(), 'is_deletable' => ! $page->isDefault(), 'public_path' => $page->publicPath(),
            'has_unpublished_changes' => $page->hasUnpublishedChanges(), 'published_at' => $page->published_at, 'updated_at' => $page->updated_at,
            'updated_by' => $page->editor?->name,
        ];
    }

    private function detail(VendorPage $page): array
    {
        return $this->summary($page) + ['seo_title' => $page->seo_title, 'meta_description' => $page->meta_description, 'blocks' => $this->blocks->present($page->draft_blocks ?? [])];
    }

    private function ensureDefaults(Vendor $vendor): void
    {
        if ($vendor->status !== 'active') {
            return;
        }
        $existing = $vendor->pages()->pluck('slug')->all();
        foreach (VendorPage::DEFAULTS as $kind => $default) {
            if (! in_array($default['slug'], $existing, true)) {
                $page = new VendorPage(['title' => $default['title'], 'slug' => $default['slug'], 'draft_blocks' => []]);
                $page->vendor_id = $vendor->id;
                $page->kind = $kind;
                $page->status = 'draft';
                try {
                    $page->saveQuietly();
                } catch (UniqueConstraintViolationException) {
                    // A concurrent request created the same default page first.
                }
            }
        }
    }

    private function slugRules(Vendor $vendor, ?VendorPage $page = null): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), [...config('platform.reserved_page_slugs'), ...array_column(VendorPage::DEFAULTS, 'slug')], true)) {
                    $fail('This page address is reserved.');
                }
            },
            Rule::unique('vendor_pages', 'slug')->where('vendor_id', $vendor->id)->ignore($page?->id)];
    }

    private function contentRules(): array
    {
        return ['seo_title' => ['nullable', 'string', 'max:70', new PlainText], 'meta_description' => ['nullable', 'string', 'max:170', new PlainText]];
    }

    private function saveUniqueSlug(VendorPage $page): void
    {
        try {
            $page->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'This page address is already in use.']);
        }
    }

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);
        Gate::authorize('viewAny', [VendorPage::class, $vendor]);
        $this->ensureDefaults($vendor);
        $order = array_flip(array_keys(VendorPage::DEFAULTS));
        $pages = $vendor->pages()->with('editor:id,name')->get()->sortBy(fn ($p) => [$order[$p->kind] ?? 99, $p->title])->values();

        return response()->json(['data' => $pages->map(fn ($p) => $this->summary($p))->all()]);
    }

    public function show(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('view', $record);

        return response()->json(['data' => $this->detail($record)]);
    }

    public function preview(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('view', $record);
        $draft = $record->draftContent();

        return response()->json(['data' => ['slug' => $record->slug, 'kind' => $record->kind, 'status' => $record->status, 'preview' => true, ...$draft, 'blocks' => $this->blocks->present($draft['blocks'])]]);
    }

    public function store(Request $request)
    {
        $vendor = $this->vendor($request);
        Gate::authorize('create', [VendorPage::class, $vendor]);
        $request->merge(['slug' => $request->input('slug') ?: Str::slug((string) $request->input('title'))]);
        $data = $request->validate(['title' => ['required', 'string', 'max:120', new PlainText], 'slug' => $this->slugRules($vendor), 'blocks' => ['nullable', 'array']] + $this->contentRules());
        $page = new VendorPage(['title' => $data['title'], 'slug' => strtolower($data['slug']), 'seo_title' => $data['seo_title'] ?? null, 'meta_description' => $data['meta_description'] ?? null, 'draft_blocks' => $this->blocks->sanitize($data['blocks'] ?? [], $vendor)]);
        $page->vendor_id = $vendor->id;
        $page->kind = VendorPage::KIND_CUSTOM;
        $page->status = 'draft';
        $page->updated_by = $request->user()->id;
        $this->saveUniqueSlug($page);

        return response()->json(['data' => $this->detail($page)], 201);
    }

    public function update(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('update', $record);
        $vendorModel = $this->vendor($request);
        $rules = ['title' => ['sometimes', 'required', 'string', 'max:120', new PlainText], 'blocks' => ['sometimes', 'nullable', 'array']] + $this->contentRules();
        if ($record->isDefault()) {
            if ($request->has('slug') && $request->input('slug') !== $record->slug) {
                throw ValidationException::withMessages(['slug' => 'The address of a default page cannot be changed.']);
            }
        } else {
            $rules['slug'] = ['sometimes', ...$this->slugRules($vendorModel, $record)];
        }
        $data = $request->validate($rules);
        if (array_key_exists('blocks', $data)) {
            $record->draft_blocks = $this->blocks->sanitize($data['blocks'], $vendorModel);
        }
        $record->fill(collect($data)->only(['title', 'seo_title', 'meta_description'])->all());
        if (isset($data['slug'])) {
            $record->slug = strtolower($data['slug']);
        }
        $record->updated_by = $request->user()->id;
        $this->saveUniqueSlug($record);

        return response()->json(['data' => $this->detail($record->fresh())]);
    }

    public function destroy(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('delete', $record);
        $record->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    public function publish(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('publish', $record);
        if (empty($record->draft_blocks)) {
            throw ValidationException::withMessages(['blocks' => 'Add at least one block before publishing.']);
        }
        $record->published_snapshot = $record->draftContent();
        $record->status = 'published';
        $record->published_at = now();
        $record->updated_by = $request->user()->id;
        $record->save();

        return response()->json(['data' => $this->detail($record->fresh()), 'message' => 'Page published.']);
    }

    public function unpublish(Request $request, string $vendor, string $page)
    {
        $record = $this->page($request, $page);
        Gate::authorize('publish', $record);
        abort_unless($record->status === 'published', 409, 'Only published pages can be unpublished.');
        $record->status = 'unpublished';
        $record->updated_by = $request->user()->id;
        $record->save();

        return response()->json(['data' => $this->detail($record->fresh()), 'message' => 'Page unpublished.']);
    }

    public function media(Request $request)
    {
        $vendor = $this->vendor($request);
        Gate::authorize('create', [VendorPage::class, $vendor]);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4000,max_height=4000']]);
        if (count(Storage::disk('public')->files('cms/'.$vendor->id)) >= (int) config('platform.max_media_per_vendor')) {
            throw ValidationException::withMessages(['image' => 'Your media library is full. Delete unused images or contact support.']);
        }
        $path = app(ImageStore::class)->store($request->file('image'), 'cms/'.$vendor->id);

        return response()->json(['data' => ['path' => $path, 'url' => Storage::disk('public')->url($path)]], 201);
    }
}
