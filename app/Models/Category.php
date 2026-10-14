<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['vendor_id', 'parent_id', 'name', 'slug', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
