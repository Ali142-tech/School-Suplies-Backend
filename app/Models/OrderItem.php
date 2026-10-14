<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id', 'vendor_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'line_total', 'status'];

    protected $casts = ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
