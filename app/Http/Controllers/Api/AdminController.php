<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreDomain;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorSubscription;
use App\Services\VendorReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function settings()
    {
        return response()->json(['data' => DB::table('platform_settings')->pluck('value', 'key')->map(fn ($value) => is_string($value) ? json_decode($value, true) ?? $value : $value)]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['marketplace_name' => 'sometimes|required|string|max:160', 'support_email' => 'sometimes|nullable|email|max:190', 'currency' => 'sometimes|required|in:PKR']);
        foreach ($data as $key => $value) {
            DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        }

        return $this->settings();
    }

    public function overview()
    {
        return response()->json(['data' => ['vendors' => Vendor::count(), 'customers' => User::where('role', 'customer')->count(), 'products' => Product::count(), 'orders' => Order::count(), 'revenue' => Order::where('payment_status', 'paid')->sum('total')]]);
    }

    public function vendors()
    {
        return ApiResponse::page(Vendor::with('owner:id,name,email')->withCount('products')->latest()->paginate(30));
    }

    public function updateVendor(Request $request, Vendor $vendor)
    {
        return response()->json(['message' => 'Use the vendor approval, suspension, or reactivation actions.'], 405);
    }

    public function vendorRequests(Request $request)
    {
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'active', 'rejected', 'suspended', 'all'], true), 422, 'Invalid vendor status filter.');
        $query = Vendor::with(['owner:id,name,email', 'category:id,name', 'domains:id,vendor_id,hostname,is_verified,provisioning_status,is_primary'])->withCount('products')->latest();
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return ApiResponse::page($query->paginate(30));
    }

    public function vendorRequest(Vendor $vendor)
    {
        return response()->json(['data' => $vendor->load(['owner:id,name,email,phone', 'category:id,name,slug', 'domains', 'memberships.user:id,name,email'])]);
    }

    public function approveVendor(Request $request, Vendor $vendor, VendorReviewService $reviews)
    {
        $data = $request->validate(['slug' => $reviews->slugRules($vendor)]);
        $approved = $reviews->approve($vendor, $data['slug'], $request->user());

        return response()->json(['data' => $approved, 'message' => 'Vendor approved. Add the hostname in Vercel/DNS, then activate its domain mapping. In-app notification was recorded when available; email depends on the configured mailer.']);
    }

    public function rejectVendor(Request $request, Vendor $vendor, VendorReviewService $reviews)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $reviews->reject($vendor, $data['reason'], $request->user()), 'message' => 'Vendor application rejected.']);
    }

    public function suspendVendor(Request $request, Vendor $vendor, VendorReviewService $reviews)
    {
        return response()->json(['data' => $reviews->suspend($vendor, $request->user()), 'message' => 'Vendor suspended and storefront domains disabled.']);
    }

    public function reactivateVendor(Request $request, Vendor $vendor, VendorReviewService $reviews)
    {
        return response()->json(['data' => $reviews->reactivate($vendor, $request->user()), 'message' => 'Vendor reactivated. Confirm DNS/Vercel setup and activate its domain mapping.']);
    }

    public function activateDomain(Request $request, StoreDomain $domain, VendorReviewService $reviews)
    {
        $request->validate(['dns_configured' => 'accepted']);

        return response()->json(['data' => $reviews->activateDomain($domain), 'message' => 'Domain mapping activated.']);
    }

    public function users()
    {
        return ApiResponse::page(User::select('id', 'name', 'email', 'role', 'is_active', 'created_at')->latest()->paginate(50));
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $user->update($data);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        if ($user->school) {
            $user->school()->update(['is_active' => $user->is_active]);
        }

        return response()->json(['data' => $user->only('id', 'name', 'email', 'role', 'is_active')]);
    }

    public function products()
    {
        return ApiResponse::page(Product::with('vendor:id,name,slug')->latest()->paginate(50));
    }

    public function updateProduct(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:180',
            'category_id' => 'sometimes|nullable|exists:categories,id',
            'price' => 'sometimes|required|numeric|min:0|max:9999999999.99',
            'status' => 'sometimes|required|in:draft,published,archived',
            'is_featured' => 'sometimes|boolean',
        ]);
        $product->update($data);

        return response()->json(['data' => $product->fresh()->load('vendor:id,name,slug')]);
    }

    public function categories()
    {
        return ApiResponse::page(Category::withCount('products')->orderBy('name')->paginate(50));
    }

    public function createCategory(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'slug' => 'required|string|max:140|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:categories,slug', 'description' => 'nullable|string|max:2000', 'parent_id' => 'nullable|exists:categories,id']);

        return response()->json(['data' => Category::create($data)], 201);
    }

    public function updateCategory(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);
        $category->update($data);

        return response()->json(['data' => $category->fresh()]);
    }

    public function orders()
    {
        return ApiResponse::page(Order::with('customer:id,name,email')->withCount('items')->latest()->paginate(50));
    }

    public function subscriptions()
    {
        return ApiResponse::page(VendorSubscription::with('vendor:id,name,slug')->latest()->paginate(50));
    }

    public function domains()
    {
        return ApiResponse::page(StoreDomain::with('vendor:id,name,slug')->latest()->paginate(50));
    }

    public function updateDomain(Request $request, StoreDomain $domain, VendorReviewService $reviews)
    {
        $data = $request->validate(['is_verified' => 'required|boolean']);
        $reviews->setDomainVerified($domain, $data['is_verified']);

        return response()->json(['data' => $domain->load('vendor:id,name,slug')]);
    }
}
