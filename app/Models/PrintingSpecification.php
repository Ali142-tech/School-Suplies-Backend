<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintingSpecification extends Model
{
    protected $fillable = ['product_id', 'sizes', 'paper_types', 'finishes', 'quantity_tiers'];

    protected $casts = ['sizes' => 'array', 'paper_types' => 'array', 'finishes' => 'array', 'quantity_tiers' => 'array'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
