<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPage;

/**
 * Any member of the vendor may view pages (owner, manager, staff);
 * only owners and managers of an active vendor may create, edit, publish, unpublish or delete.
 */
class VendorPagePolicy
{
    private function role(User $user, Vendor $vendor): ?string
    {
        return $vendor->roleFor($user);
    }

    private function canManage(User $user, Vendor $vendor): bool
    {
        return $vendor->status === 'active' && in_array($this->role($user, $vendor), ['owner', 'manager'], true);
    }

    public function viewAny(User $user, Vendor $vendor): bool
    {
        return $this->role($user, $vendor) !== null;
    }

    public function view(User $user, VendorPage $page): bool
    {
        return $this->viewAny($user, $page->vendor);
    }

    public function create(User $user, Vendor $vendor): bool
    {
        return $this->canManage($user, $vendor);
    }

    public function update(User $user, VendorPage $page): bool
    {
        return $this->canManage($user, $page->vendor);
    }

    public function publish(User $user, VendorPage $page): bool
    {
        return $this->canManage($user, $page->vendor);
    }

    public function delete(User $user, VendorPage $page): bool
    {
        return ! $page->isDefault() && $this->canManage($user, $page->vendor);
    }
}
