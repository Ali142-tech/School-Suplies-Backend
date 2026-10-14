<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorApplicationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:vendors,slug', function ($attribute, $value, $fail) {
            if (in_array(strtolower($value), config('platform.reserved_slugs'), true)) {
                $fail('This storefront slug is reserved.');
            }
        }], 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:32', 'description' => 'nullable|string|max:5000']);
        $data['requested_slug'] = $data['slug'];
        $vendor = DB::transaction(function () use ($request, $data) {
            $vendor = Vendor::create([...$data, 'owner_id' => $request->user()->id, 'status' => 'pending']);
            $vendor->memberships()->create(['user_id' => $request->user()->id, 'role' => 'manager']);
            $vendor->settings()->create([]);
            $request->user()->update(['role' => 'vendor']);

            return $vendor;
        });

        return response()->json(['data' => $vendor, 'message' => 'Vendor application submitted for review.'], 201);
    }
}
