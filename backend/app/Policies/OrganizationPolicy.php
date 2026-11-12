<?php
namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/** Permission checks run inside the active Spatie team (organization). */
class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->can('organizations.view');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update');
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->can('organizations.delete');
    }
}
