<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class VendorRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'business_name' => 'required|string|max:160',
            'email' => 'required|email:rfc|max:190|unique:users,email',
            'phone' => 'required|string|max:32',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'business_address' => 'required|string|max:1000',
            'category_id' => 'required|integer|exists:categories,id',
            'terms_accepted' => 'accepted',
            'store_slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:vendors,slug', function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), config('platform.reserved_slugs'), true)) {
                    $fail('This storefront slug is reserved.');
                }
            }],
        ]);
        abort_unless(Category::whereKey($data['category_id'])->whereNull('vendor_id')->where('is_active', true)->exists(), 422, 'Choose an active platform category.');

        [$user, $vendor] = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'password' => $data['password'], 'role' => 'vendor',
            ]);
            $vendor = $user->ownedVendors()->create([
                'name' => $data['business_name'], 'slug' => strtolower($data['store_slug']), 'requested_slug' => strtolower($data['store_slug']),
                'email' => $data['email'], 'phone' => $data['phone'], 'business_address' => $data['business_address'],
                'category_id' => $data['category_id'], 'status' => 'pending',
            ]);
            $vendor->memberships()->create(['user_id' => $user->id, 'role' => 'manager']);
            $vendor->settings()->create([]);

            return [$user, $vendor];
        });

        return response()->json([
            'data' => ['user' => $user, 'vendor' => $vendor, 'approval_status' => $vendor->status],
            'token' => $user->createToken('web')->plainTextToken,
            'message' => 'Your account is ready. Your store is pending approval.',
        ], 201);
    }
}
