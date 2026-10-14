<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => $request->user()->orders()->with('items.vendor:id,name,slug')->latest()->paginate(20)]);
    }

    public function show(Request $request, Order $order)
    {
        abort_unless($order->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $order->load('items.vendor:id,name,slug')]);
    }

    public function checkout(CheckoutRequest $request)
    {
        $data = $request->validated();
        // Vendor domain / school account: the whole order must belong to that one vendor. Marketplace requests keep the shared multi-vendor behaviour.
        $storefrontVendor = Storefront::purchaseVendor($request);
        $order = DB::transaction(function () use ($request, $data, $storefrontVendor) {
            $lines = [];
            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::where('status', 'published')->whereHas('vendor', fn ($q) => $q->where('status', 'active'))->with('inventory')->lockForUpdate()->findOrFail($line['product_id']);
                abort_if($storefrontVendor && $product->vendor_id !== $storefrontVendor->id, 422, 'This store only sells its own products.');
                $inventory = Inventory::where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                if ($inventory->quantity < $line['quantity']) {
                    abort(422, "Insufficient stock for {$product->name}.");
                } $qty = $line['quantity'];
                $price = (float) $product->price;
                $subtotal += $price * $qty;
                $lines[] = [$product, $qty, $price, $inventory];
            } $order = Order::create(['number' => 'PT-'.strtoupper(Str::random(10)), 'customer_id' => $request->user()->id, 'status' => 'pending', 'payment_status' => 'unpaid', 'subtotal' => $subtotal, 'shipping_total' => 0, 'total' => $subtotal, 'currency' => 'PKR', 'shipping_address' => $data['shipping_address']]);
            foreach ($lines as [$product,$qty,$price,$inventory]) {
                OrderItem::create(['order_id' => $order->id, 'vendor_id' => $product->vendor_id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty, 'status' => 'pending']);
                $inventory->decrement('quantity', $qty);
            }

            return $order;
        });

        return response()->json(['data' => $order->load('items'), 'message' => 'Order created; payment is not collected by this API.'], 201);
    }
}
