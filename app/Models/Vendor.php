<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use SoftDeletes;

    protected $fillable = ['owner_id', 'name', 'slug', 'requested_slug', 'email', 'phone', 'description', 'logo_path', 'status', 'business_address', 'category_id', 'reviewed_by', 'reviewed_at', 'approved_at', 'rejected_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function memberships()
    {
        return $this->hasMany(VendorMembership::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'vendor_memberships')->withPivot('role')->withTimestamps();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /** Categories owned by this vendor (platform-wide categories have a null vendor_id and are not included). */
    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function settings()
    {
        return $this->hasOne(StoreSetting::class);
    }

    public function domains()
    {
        return $this->hasMany(StoreDomain::class);
    }

    public function quotationRequests()
    {
        return $this->hasMany(QuotationRequest::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(VendorSubscription::class);
    }

    public function pages()
    {
        return $this->hasMany(VendorPage::class);
    }

    /** 'owner', the membership role, or null when the user does not belong to this vendor. */
    public function roleFor(User $user): ?string
    {
        if ($this->owner_id === $user->id) {
            return 'owner';
        }

        return $this->memberships()->where('user_id', $user->id)->value('role');
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
