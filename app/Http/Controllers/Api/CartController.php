<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Support\Storefront;
use Illuminate\Http\Request;

class CartController extends Controller
{
    private function cart(Request $request): Cart
    {
        return Cart::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /** The cart's items as this request may see them: only the storefront vendor's items on a vendor domain / for a school. */
    private function items(Request $request)
    {
        $vendor = Storefront::purchaseVendor($request);

        return $this->cart($request)->items()->when($vendor, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('vendor_id', $vendor->id)));
    }

    public function show(Request $request)
    {
        $cart = $this->cart($request);
        $cart->setRelation('items', $this->items($request)->with('product.vendor:id,name,slug', 'product.inventory')->get());

        return response()->json(['data' => $cart]);
    }

    public function add(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id', 'quantity' => 'required|integer|min:1|max:100']);
        $product = Product::where('status', 'published')->whereHas('vendor', fn ($q) => $q->where('status', 'active'))->findOrFail($data['product_id']);
        $vendor = Storefront::purchaseVendor($request);
        abort_if($vendor && $product->vendor_id !== $vendor->id, 422, 'This store only sells its own products.');

        $item = $this->cart($request)->items()->firstOrNew(['product_id' => $product->id]);
        $item->quantity = min(100, $item->quantity + $data['quantity']);
        $item->save();

        return response()->json(['data' => $item->load('product')], 201);
    }

    public function update(Request $request, int $item)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:100']);
        $cartItem = $this->items($request)->findOrFail($item);
        $cartItem->update($data);

        return response()->json(['data' => $cartItem->load('product')]);
    }

    public function remove(Request $request, int $item)
    {
        $this->items($request)->findOrFail($item)->delete();

        return response()->json(['message' => 'Cart item removed.']);
    }
}
