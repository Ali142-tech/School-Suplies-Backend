<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorMembership extends Model
{
    protected $fillable = ['vendor_id', 'user_id', 'role'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
