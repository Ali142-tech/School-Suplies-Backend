<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class VendorRegistrationController extends Controller
{
    public function store(Request $request)
    {
        // Only name, email and password are required; category, address, phone etc. can be completed later from the vendor profile.
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'business_name' => 'nullable|string|max:160',
            'email' => 'required|email:rfc|max:190|unique:users,email',
            'phone' => 'nullable|string|max:32',
            'category_id' => 'nullable|integer|exists:categories,id',
            'password' => ['required', $request->has('password_confirmation') ? 'confirmed' : 'string', Password::min(10)->letters()->numbers()],
            'store_slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:vendors,slug', function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), config('platform.reserved_slugs'), true)) {
                    $fail('This storefront slug is reserved.');
                }
            }],
        ], ['email.unique' => 'An account already exists for this email address. Sign in or reset your password.']);
        abort_unless(empty($data['category_id']) || Category::whereKey($data['category_id'])->whereNull('vendor_id')->where('is_active', true)->exists(), 422, 'Choose an active platform category.');
        $slug = strtolower($data['store_slug'] ?? $this->uniqueSlug($data['business_name'] ?? $data['name']));

        [$user, $vendor] = DB::transaction(function () use ($data, $slug) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'password' => $data['password'], 'role' => 'vendor',
            ]);
            $vendor = $user->ownedVendors()->create([
                'name' => $data['business_name'] ?? $data['name'], 'slug' => $slug, 'requested_slug' => $slug,
                'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'category_id' => $data['category_id'] ?? null, 'status' => 'pending',
            ]);
            $vendor->memberships()->create(['user_id' => $user->id, 'role' => 'manager']);
            $vendor->settings()->create([]);

            return [$user, $vendor];
        });

        return response()->json([
            'data' => ['user' => $user, 'vendor' => $vendor, 'approval_status' => $vendor->status],
            'token' => \App\Support\TenantAccess::issueToken($user, null),
            'message' => 'Your account is ready. Your store is pending approval.',
        ], 201);
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::limit(Str::slug($source) ?: 'store', 50, '');
        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (in_array($slug, config('platform.reserved_slugs'), true) || Vendor::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
