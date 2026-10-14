<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['number', 'customer_id', 'status', 'payment_status', 'subtotal', 'shipping_total', 'total', 'currency', 'shipping_address'];

    protected $casts = ['shipping_address' => 'array', 'subtotal' => 'decimal:2', 'shipping_total' => 'decimal:2', 'total' => 'decimal:2'];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
