<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreDomain extends Model
{
    protected $fillable = ['vendor_id', 'hostname', 'is_verified', 'provisioning_status', 'source', 'is_primary', 'verified_at'];

    protected $casts = ['is_verified' => 'boolean', 'is_primary' => 'boolean', 'verified_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
