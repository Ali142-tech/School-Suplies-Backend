<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['vendor_id', 'category_id', 'name', 'slug', 'description', 'price', 'currency', 'type', 'status', 'is_featured'];

    protected $casts = ['price' => 'decimal:2', 'is_featured' => 'boolean'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class);
    }

    public function printingSpecification()
    {
        return $this->hasOne(PrintingSpecification::class);
    }
}
