<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use App\Support\ApiResponse;
use App\Support\Storefront;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function vendors(Request $request)
    {
        $tenant = Storefront::tenant($request);

        return ApiResponse::page(Vendor::where('status', 'active')->when($tenant, fn ($q) => $q->whereKey($tenant->id))->select('id', 'name', 'slug', 'description', 'logo_path')->paginate(20));
    }

    public function vendor(Request $request, string $slug)
    {
        // On a vendor domain only that vendor's own profile is reachable.
        abort_if(Storefront::tenant($request) && Storefront::tenant($request)->slug !== $slug, 404);
        $vendor = Vendor::where('slug', $slug)->where('status', 'active')->with(['settings:id,vendor_id,brand_color,banner_path'])->firstOrFail();

        return response()->json(['data' => $this->publicVendor($vendor)]);
    }

    public function resolve(Request $request)
    {
        $slug = $request->query('slug');
        $host = strtolower(rtrim(trim($request->query('host', '')), '.'));
        abort_unless($slug || $host, 422, 'Provide a storefront slug or host.');
        $query = Vendor::where('status', 'active');
        if ($slug) {
            $query->where('slug', $slug);
        } else {
            abort_unless(filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', $host), 422, 'Invalid storefront hostname.');
            $query->whereHas('domains', fn ($d) => $d->where('hostname', $host)->where('is_verified', true)->where('provisioning_status', 'active'));
        } $vendor = $query->with(['settings:id,vendor_id,brand_color,banner_path'])->firstOrFail();
        abort_if(Storefront::tenant($request) && Storefront::tenant($request)->id !== $vendor->id, 404);

        return response()->json(['data' => $this->publicVendor($vendor)]);
    }

    private function publicVendor(Vendor $vendor): array
    {
        return [...$vendor->only('id', 'name', 'slug', 'description', 'email', 'phone', 'logo_path'), 'settings' => $vendor->settings?->only('brand_color', 'banner_path')];
    }

    public function categories(Request $request)
    {
        $tenant = Storefront::tenant($request);
        // Marketplace: the shared categories. Vendor domain: the vendor's own categories plus shared ones that hold at least one of its published products.
        $query = Category::where('is_active', true)->when(
            $tenant,
            fn ($q) => $q->where(fn ($w) => $w->where('vendor_id', $tenant->id)->orWhere(fn ($s) => $s->whereNull('vendor_id')->whereHas('products', fn ($p) => $p->where('vendor_id', $tenant->id)->where('status', 'published')))),
            fn ($q) => $q->whereNull('vendor_id'),
        );

        return response()->json(['data' => $query->orderBy('name')->get(['id', 'name', 'slug', 'description'])]);
    }

    public function products(Request $request)
    {
        $query = Product::with(['vendor:id,name,slug', 'category:id,name,slug', 'images', 'inventory'])->where('status', 'published')->whereHas('vendor', fn ($q) => $q->where('status', 'active'));
        $query->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->query('q').'%')->orWhere('description', 'like', '%'.$request->query('q').'%')));
        $query->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->query('category'))));
        // The vendor of a vendor-domain request comes from the verified domain; a ?vendor= value is only a marketplace filter.
        $tenant = Storefront::tenant($request);
        $query->when($tenant, fn ($q) => $q->where('vendor_id', $tenant->id));
        $query->when(! $tenant && $request->filled('vendor'), fn ($q) => $q->whereHas('vendor', fn ($v) => $v->where('slug', $request->query('vendor'))));
        $query->when($request->filled('min_price'), fn ($q) => $q->where('price', '>=', $request->query('min_price')));
        $query->when($request->filled('max_price'), fn ($q) => $q->where('price', '<=', $request->query('max_price')));
        if ($request->boolean('available')) {
            $query->whereHas('inventory', fn ($i) => $i->where('quantity', '>', 0));
        } $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        abort_unless(in_array($sort, ['created_at', 'price', 'name'], true) && in_array($direction, ['asc', 'desc'], true), 422, 'Invalid sort.');

        return ApiResponse::page($query->orderBy($sort, $direction)->paginate(min(max((int) $request->query('per_page', 16), 1), 100)));
    }

    public function product(Request $request, Product $product)
    {
        abort_unless($product->status === 'published' && $product->vendor()->where('status', 'active')->exists(), 404);
        abort_if(Storefront::tenant($request) && $product->vendor_id !== Storefront::tenant($request)->id, 404);

        return response()->json(['data' => $product->load(['vendor:id,name,slug,description', 'category:id,name,slug', 'images', 'inventory', 'printingSpecification'])]);
    }
}
