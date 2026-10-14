<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $fillable = ['product_id', 'quantity', 'low_stock_threshold'];

    protected $casts = ['quantity' => 'integer', 'low_stock_threshold' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
