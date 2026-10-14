<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtworkUpload extends Model
{
    protected $fillable = ['quotation_request_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['path'];

    public function quotation()
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }
}
