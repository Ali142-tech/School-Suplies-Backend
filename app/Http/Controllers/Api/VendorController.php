<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\School;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMembership;
use App\Rules\PlainText;
use App\Services\Cms\SiteSettings;
use App\Services\ImageStore;
use App\Services\VendorReviewService;
use App\Support\ApiResponse;
use App\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    private function vendor(Request $request): Vendor
    {
        return $request->attributes->get('vendor');
    }

    private function manager(Request $request): void
    {
        abort_unless(in_array($request->attributes->get('vendor_role'), ['owner', 'manager'], true), 403, 'Manager permission required.');
    }

    public function dashboard(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json(['data' => ['vendor' => $v->only('id', 'name', 'slug', 'requested_slug', 'status', 'rejection_reason', 'reviewed_at', 'approved_at') + ['storefront_url' => Storefront::url($v)], 'domains' => $v->domains()->get(['id', 'hostname', 'is_verified', 'provisioning_status', 'is_primary']), 'products' => $v->products()->count(), 'orders' => OrderItem::where('vendor_id', $v->id)->distinct('order_id')->count('order_id'), 'low_stock' => Inventory::whereHas('product', fn ($p) => $p->where('vendor_id', $v->id))->whereColumn('quantity', '<=', 'low_stock_threshold')->count(), 'sales' => OrderItem::where('vendor_id', $v->id)->whereIn('status', ['processing', 'shipped', 'delivered'])->sum('line_total')]]);
    }

    public function application(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json(['data' => ['vendor' => $v->only('id', 'name', 'slug', 'requested_slug', 'email', 'phone', 'business_address', 'category_id', 'status', 'rejection_reason', 'reviewed_at', 'approved_at') + ['storefront_url' => Storefront::url($v)], 'domains' => $v->domains()->get(['id', 'hostname', 'is_verified', 'provisioning_status', 'is_primary'])]]);
    }

    public function reapply(Request $request)
    {
        $this->manager($request);
        $vendor = $this->vendor($request);
        abort_unless($vendor->owner_id === $request->user()->id && $vendor->status === 'rejected', 403, 'Only the rejected store owner can resubmit this application.');
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160', 'business_address' => 'sometimes|required|string|max:1000',
            'category_id' => 'sometimes|required|integer|exists:categories,id',
            'store_slug' => ['sometimes', 'required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('vendors', 'slug')->ignore($vendor->id), function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), config('platform.reserved_slugs'), true)) {
                    $fail('This storefront slug is reserved.');
                }
            }],
        ]);
        if (isset($data['category_id'])) {
            abort_unless(Category::whereKey($data['category_id'])->whereNull('vendor_id')->where('is_active', true)->exists(), 422, 'Choose an active platform category.');
        }
        $vendor->update([...array_filter(['name' => $data['name'] ?? null, 'business_address' => $data['business_address'] ?? null, 'category_id' => $data['category_id'] ?? null]), ...isset($data['store_slug']) ? ['slug' => strtolower($data['store_slug']), 'requested_slug' => strtolower($data['store_slug'])] : [], 'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'approved_at' => null, 'rejected_at' => null, 'rejection_reason' => null]);

        return response()->json(['data' => $vendor->fresh(), 'message' => 'Your application has been resubmitted for review.']);
    }

    public function products(Request $request)
    {
        return ApiResponse::page($this->vendor($request)->products()->with(['category', 'inventory', 'images'])->paginate(30));
    }

    public function storeProduct(ProductRequest $request)
    {
        $this->manager($request);
        $v = $this->vendor($request);
        $data = $request->validated();
        if (isset($data['category_id'])) {
            abort_unless(Category::whereKey($data['category_id'])->where(fn ($q) => $q->whereNull('vendor_id')->orWhere('vendor_id', $v->id))->exists(), 422, 'Category is not available to this store.');
        } $qty = $data['quantity'] ?? 0;
        unset($data['quantity'],$data['low_stock_threshold']);
        $data['vendor_id'] = $v->id;
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $product = $v->products()->create($data);
        $product->inventory()->create(['quantity' => $qty, 'low_stock_threshold' => $request->integer('low_stock_threshold', 5)]);

        return response()->json(['data' => $product->load(['inventory', 'category'])], 201);
    }

    public function updateProduct(ProductRequest $request, string $vendor, Product $product)
    {
        $this->manager($request);
        abort_unless($product->vendor_id === $this->vendor($request)->id, 404);
        $data = $request->validated();
        $qty = $data['quantity'] ?? null;
        unset($data['quantity'],$data['low_stock_threshold']);
        if (isset($data['category_id'])) {
            abort_unless(Category::whereKey($data['category_id'])->where(fn ($q) => $q->whereNull('vendor_id')->orWhere('vendor_id', $this->vendor($request)->id))->exists(), 422);
        } $product->update($data);
        if ($qty !== null) {
            $product->inventory()->updateOrCreate([], ['quantity' => $qty, 'low_stock_threshold' => $request->integer('low_stock_threshold', 5)]);
        }

        return response()->json(['data' => $product->load(['inventory', 'category'])]);
    }

    public function deleteProduct(Request $request, string $vendor, Product $product)
    {
        $this->manager($request);
        abort_unless($product->vendor_id === $this->vendor($request)->id, 404);
        $product->delete();

        return response()->json(['message' => 'Product archived.']);
    }

    public function image(Request $request, string $vendor, Product $product)
    {
        $this->manager($request);
        abort_unless($product->vendor_id === $this->vendor($request)->id, 404);
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4000,max_height=4000', 'alt_text' => 'nullable|string|max:255']);
        $path = app(ImageStore::class)->store($request->file('image'), 'products');
        $image = $product->images()->create(['path' => $path, 'alt_text' => $data['alt_text'] ?? null]);

        return response()->json(['data' => $image], 201);
    }

    public function inventory(Request $request, string $vendor, Product $product)
    {
        abort_unless($product->vendor_id === $this->vendor($request)->id, 404);
        $data = $request->validate(['quantity' => 'required|integer|min:0', 'low_stock_threshold' => 'sometimes|integer|min:0']);
        $this->manager($request);

        return response()->json(['data' => $product->inventory()->updateOrCreate([], $data)]);
    }

    public function orders(Request $request)
    {
        $v = $this->vendor($request);
        $orders = Order::whereHas('items', fn ($q) => $q->where('vendor_id', $v->id))->with(['items' => fn ($q) => $q->where('vendor_id', $v->id), 'customer:id,name,email,role', 'customer.school' => fn ($q) => $q->where('vendor_id', $v->id)])->latest()->paginate(30);

        return ApiResponse::page($orders);
    }

    public function schools(Request $request)
    {
        $this->manager($request);
        $v = $this->vendor($request);
        $schools = $v->schools()->with('user:id,name,email,phone')->withCount(['user as orders_count' => fn ($q) => $q->whereHas('orders.items', fn ($items) => $items->where('vendor_id', $v->id))])->latest()->paginate(30);

        return ApiResponse::page($schools);
    }

    public function updateSchool(Request $request, string $vendor, School $school)
    {
        $this->manager($request);
        abort_unless($school->vendor_id === $this->vendor($request)->id, 404);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $school->update($data);
        $school->user()->update(['is_active' => $school->is_active]);
        if (! $school->is_active) {
            $school->user->tokens()->delete();
        }

        return response()->json(['data' => $school->load('user:id,name,email,phone')]);
    }

    public function updateOrder(Request $request, string $vendor, Order $order)
    {
        $v = $this->vendor($request);
        abort_unless($order->items()->where('vendor_id', $v->id)->exists(), 404);
        $data = $request->validate(['status' => 'required|in:processing,shipped,delivered,cancelled']);
        $order->items()->where('vendor_id', $v->id)->update(['status' => $data['status']]);

        return response()->json(['data' => $order->fresh()->load(['items' => fn ($q) => $q->where('vendor_id', $v->id)])]);
    }

    public function settings(Request $request)
    {
        $vendor = $this->vendor($request);

        return response()->json(['data' => SiteSettings::withUrls($vendor, $vendor->settings()->firstOrCreate([]))]);
    }

    public function updateSettings(Request $request)
    {
        $this->manager($request);
        $data = $request->validate(SiteSettings::rules());
        $vendor = $this->vendor($request);
        $settings = $vendor->settings()->firstOrCreate([]);
        $attributes = [];
        if (array_key_exists('brand_color', $data)) {
            $attributes['brand_color'] = $data['brand_color'];
        }
        if ($request->hasFile('banner')) {
            $attributes['banner_path'] = app(ImageStore::class)->store($request->file('banner'), 'store-banners', 'banner');
        }
        if (array_key_exists('settings', $data)) {
            $attributes['settings'] = array_replace($settings->settings ?? [], $data['settings'] ?? []);
        }
        if ($request->hasFile('logo')) {
            $vendor->update(['logo_path' => app(ImageStore::class)->store($request->file('logo'), 'cms/'.$vendor->id, 'logo')]);
        }
        $settings->update($attributes);

        return response()->json(['data' => SiteSettings::withUrls($vendor, $settings->fresh())]);
    }

    public function updateProfile(Request $request)
    {
        $this->manager($request);
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'email' => 'required|email:rfc|max:190',
            'phone' => 'nullable|string|max:32',
            'business_address' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
        ]);
        $vendor = $this->vendor($request);
        $vendor->update($data);

        return response()->json(['data' => $vendor->fresh(), 'message' => 'Store profile saved.']);
    }

    public function domains(Request $request)
    {
        return response()->json(['data' => $this->vendor($request)->domains()->get(['id', 'hostname', 'is_verified', 'created_at'])]);
    }

    public function addDomain(Request $request)
    {
        $this->manager($request);
        $data = $request->validate(['hostname' => app(VendorReviewService::class)->hostnameRules()]);
        $domain = app(VendorReviewService::class)->addCustomDomain($this->vendor($request), $data['hostname']);

        return response()->json(['data' => $domain, 'message' => 'Domain added; verification is required before storefront resolution.'], 201);
    }

    public function removeDomain(Request $request, string $vendor, StoreDomain $domain)
    {
        $this->manager($request);
        abort_unless($domain->vendor_id === $this->vendor($request)->id, 404);
        $domain->delete();

        return response()->json(['message' => 'Domain mapping removed.']);
    }

    public function members(Request $request)
    {
        $this->manager($request);

        return ApiResponse::page($this->vendor($request)->memberships()->with('user:id,name,email')->paginate(50));
    }

    public function addMember(Request $request)
    {
        $this->manager($request);
        $data = $request->validate(['email' => 'required|email|exists:users,email', 'role' => 'required|in:manager,staff']);
        $user = User::where('email', $data['email'])->firstOrFail();
        abort_unless($user->is_active, 422, 'Inactive accounts cannot be added.');
        abort_if(in_array($user->role, ['school', 'platform_admin'], true) || ($user->vendor_id && $user->vendor_id !== $this->vendor($request)->id), 422, 'This account cannot be added to this store.');
        abort_if($user->id === $this->vendor($request)->owner_id, 422, 'The store owner already has full access.');
        $membership = $this->vendor($request)->memberships()->updateOrCreate(['user_id' => $user->id], ['role' => $data['role']]);

        return response()->json(['data' => $membership->load('user:id,name,email')], 201);
    }

    public function removeMember(Request $request, string $vendor, VendorMembership $membership)
    {
        $this->manager($request);
        abort_unless($membership->vendor_id === $this->vendor($request)->id, 404);
        abort_if($membership->user_id === $this->vendor($request)->owner_id, 422, 'The store owner cannot be removed.');
        $membership->delete();

        return response()->json(['message' => 'Vendor membership removed.']);
    }

    public function categories(Request $request)
    {
        $categories = $this->vendor($request)->categories()->withCount('products')->orderBy('name')->get(['id', 'name', 'slug', 'description', 'is_active']);

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request)
    {
        $this->manager($request);
        $v = $this->vendor($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', new PlainText, Rule::unique('categories', 'name')->where('vendor_id', $v->id)],
            'description' => ['nullable', 'string', 'max:500', new PlainText],
        ]);
        abort_if($v->categories()->count() >= 100, 422, 'A store can have at most 100 categories.');
        $category = $v->categories()->create([...$data, 'slug' => $this->uniqueCategorySlug($v, $data['name']), 'is_active' => true]);

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, string $vendor, Category $category)
    {
        $this->manager($request);
        $v = $this->vendor($request);
        abort_unless($category->vendor_id === $v->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', new PlainText, Rule::unique('categories', 'name')->where('vendor_id', $v->id)->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:500', new PlainText],
            'is_active' => 'sometimes|boolean',
        ]);
        $category->update($data);

        return response()->json(['data' => $category->fresh()]);
    }

    public function deleteCategory(Request $request, string $vendor, Category $category)
    {
        $this->manager($request);
        abort_unless($category->vendor_id === $this->vendor($request)->id, 404);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function uniqueCategorySlug(Vendor $vendor, string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'category', 100, '');
        $slug = $base;
        for ($i = 2; $vendor->categories()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
