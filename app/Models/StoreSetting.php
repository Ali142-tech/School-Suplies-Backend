<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    protected $fillable = ['vendor_id', 'settings', 'brand_color', 'banner_path'];

    protected $casts = ['settings' => 'array'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
