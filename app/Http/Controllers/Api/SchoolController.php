<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\School;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SchoolController extends Controller
{
    private function vendor(Request $request)
    {
        return $request->attributes->get('tenant_vendor');
    }

    private function school(Request $request): School
    {
        $school = $request->user()->school;
        abort_unless($school && $school->is_active && $school->vendor_id === $this->vendor($request)->id, 403, 'This school account is not authorized for this store.');

        return $school;
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:190|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()], 'phone' => 'nullable|string|max:32',
            'school_name' => 'required|string|max:180', 'school_phone' => 'nullable|string|max:32', 'address' => 'nullable|string|max:2000',
        ]);

        $vendor = $this->vendor($request);
        $result = DB::transaction(function () use ($data, $vendor) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'phone' => $data['phone'] ?? null, 'role' => 'school', 'is_active' => true]);
            $user->school()->create(['vendor_id' => $vendor->id, 'name' => $data['school_name'], 'phone' => $data['school_phone'] ?? null, 'address' => $data['address'] ?? null]);

            return ['user' => $user, 'token' => $user->createToken('school-web')->plainTextToken];
        });

        return response()->json(['data' => $result['user']->load('school'), 'token' => $result['token']], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->where('is_active', true)->with('school')->first();
        if (! $user || $user->role !== 'school' || ! $user->school?->is_active || $user->school->vendor_id !== $this->vendor($request)->id || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The supplied credentials are invalid for this store.'], 422);
        }

        return response()->json(['data' => $user->load('school'), 'token' => $user->createToken('school-web')->plainTextToken]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $this->school($request)->load('user:id,name,email,phone')->only('id', 'name', 'phone', 'address', 'user')]);
    }

    public function updateProfile(Request $request)
    {
        $school = $this->school($request);
        $data = $request->validate(['name' => 'required|string|max:180', 'phone' => 'nullable|string|max:32', 'address' => 'nullable|string|max:2000', 'contact_name' => 'required|string|max:160', 'contact_phone' => 'nullable|string|max:32']);
        $school->update(['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null]);
        $user = $school->user;
        $user->update(['name' => $data['contact_name'], 'phone' => $data['contact_phone'] ?? null]);

        return response()->json(['data' => $school->fresh()->load('user:id,name,email,phone')->only('id', 'name', 'phone', 'address', 'user')]);
    }

    public function logout(Request $request)
    {
        $this->school($request);
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function products(Request $request)
    {
        $this->school($request);
        $vendor = $this->vendor($request);
        $query = Product::where('vendor_id', $vendor->id)->where('status', 'published')->with(['category:id,name,slug', 'images', 'inventory', 'printingSpecification']);
        if ($request->filled('type')) {
            $request->validate(['type' => 'in:supply,print']);
            $query->where('type', $request->query('type'));
        }

        return ApiResponse::page($query->orderBy('name')->paginate(24));
    }

    public function orders(Request $request)
    {
        $school = $this->school($request);

        return ApiResponse::page($request->user()->orders()->whereHas('items', fn ($q) => $q->where('vendor_id', $school->vendor_id))->with(['items' => fn ($q) => $q->where('vendor_id', $school->vendor_id)])->latest()->paginate(20));
    }

    public function showOrder(Request $request, Order $order)
    {
        $school = $this->school($request);
        abort_unless($order->customer_id === $request->user()->id && $order->items()->where('vendor_id', $school->vendor_id)->exists(), 404);

        return response()->json(['data' => $order->load(['items' => fn ($q) => $q->where('vendor_id', $school->vendor_id)])]);
    }

    public function checkout(CheckoutRequest $request)
    {
        $school = $this->school($request);
        $data = $request->validated();
        $vendorId = $school->vendor_id;
        $order = DB::transaction(function () use ($request, $data, $vendorId) {
            $lines = [];
            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::where('vendor_id', $vendorId)->where('status', 'published')->whereHas('vendor', fn ($q) => $q->where('status', 'active'))->with('inventory')->lockForUpdate()->findOrFail($line['product_id']);
                $inventory = Inventory::where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                abort_if($inventory->quantity < $line['quantity'], 422, "Insufficient stock for {$product->name}.");
                $price = (float) $product->price;
                $subtotal += $price * $line['quantity'];
                $lines[] = [$product, $line['quantity'], $price, $inventory];
            }

            $order = Order::create(['number' => 'PT-'.strtoupper(Str::random(10)), 'customer_id' => $request->user()->id, 'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => $subtotal, 'shipping_total' => 0, 'total' => $subtotal, 'currency' => 'PKR', 'shipping_address' => $data['shipping_address']]);
            foreach ($lines as [$product, $quantity, $price, $inventory]) {
                OrderItem::create(['order_id' => $order->id, 'vendor_id' => $vendorId, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $price, 'line_total' => $price * $quantity, 'status' => 'pending']);
                $inventory->decrement('quantity', $quantity);
            }

            return $order;
        });

        return response()->json(['data' => $order->load('items'), 'message' => 'Order created; payment is not collected by this API.'], 201);
    }
}
