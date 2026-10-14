<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationRequest extends Model
{
    protected $fillable = ['reference', 'customer_id', 'vendor_id', 'contact_name', 'email', 'product_type', 'quantity', 'size', 'paper_type', 'finish', 'notes', 'status', 'quoted_price', 'vendor_response', 'responded_at'];

    protected $casts = ['quoted_price' => 'decimal:2', 'responded_at' => 'datetime'];

    public function artwork()
    {
        return $this->hasMany(ArtworkUpload::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
