<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorSubscription extends Model
{
    protected $fillable = ['vendor_id', 'plan', 'status', 'starts_at', 'ends_at', 'billing_provider', 'provider_reference'];

    protected $hidden = ['provider_reference'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
