<?php
namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles are provisioned PER ORGANIZATION using Spatie's teams feature
 * (team key = organization_id). Permissions are global; each org gets its
 * own role rows mapped to the permission sets below. This is what lets one
 * user be client_admin in Client A and client_readonly in Client B.
 */
class RoleProvisioner
{
    public const PERMISSIONS = [
        'organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete',
        'members.view', 'members.invite', 'members.update', 'members.remove',
        'settings.view', 'settings.update',
        'audit.view',
        'dashboard.view',
        'social.view', 'social.connect', 'social.test', 'social.disconnect',
        'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit',
        'content.approve', 'content.publish', 'content.schedule',
        'media.view', 'media.upload', 'media.delete',
    ];

    public const ROLE_PERMISSIONS = [
        'super_admin' => '*',
        'agency_admin' => ['organizations.view', 'organizations.create', 'organizations.update', 'members.view', 'members.invite', 'members.update', 'members.remove', 'settings.view', 'settings.update', 'audit.view', 'dashboard.view'],
        'agency_marketing_manager' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'social.disconnect', 'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'media.delete'],
        'agency_marketing_executive' => ['organizations.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'content.view', 'content.create', 'content.edit', 'content.submit', 'content.publish', 'content.schedule', 'media.view', 'media.upload'],
        'agency_account_manager' => ['organizations.view', 'members.view', 'members.invite', 'settings.view', 'settings.update', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'content.view', 'content.create', 'content.edit', 'content.submit', 'content.approve', 'content.publish', 'content.schedule', 'media.view', 'media.upload'],
        'agency_analyst' => ['organizations.view', 'audit.view', 'dashboard.view', 'social.view', 'content.view', 'media.view'],
        'client_owner' => '*',
        'client_admin' => ['organizations.view', 'organizations.update', 'members.view', 'members.invite', 'members.update', 'members.remove', 'settings.view', 'settings.update', 'audit.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'social.disconnect', 'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit', 'content.approve', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'media.delete'],
        'client_marketing_approver' => ['organizations.view', 'members.view', 'dashboard.view', 'social.view', 'content.view', 'content.approve', 'media.view'],
        'client_sales_manager' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view', 'content.view'],
        'client_sales_agent' => ['organizations.view', 'dashboard.view'],
        'client_readonly' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view'],
    ];

    public function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function provisionFor(Organization $organization): void
    {
        $this->ensurePermissions();
        setPermissionsTeamId($organization->id);
        foreach (self::ROLE_PERMISSIONS as $roleName => $perms) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'organization_id' => $organization->id,
            ]);
            $role->syncPermissions($perms === '*' ? self::PERMISSIONS : $perms);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function assign(Organization $organization, User $user, string $roleName): void
    {
        if (!array_key_exists($roleName, self::ROLE_PERMISSIONS)) {
            throw new \InvalidArgumentException("Unknown role: {$roleName}");
        }
        setPermissionsTeamId($organization->id);
        // Replace roles within this organization only.
        $user->roles()->wherePivot('organization_id', $organization->id)->detach();
        $user->assignRole($roleName);
    }

    /** Role names + permission names for a user inside one organization. */
    public function contextFor(Organization $organization, User $user): array
    {
        setPermissionsTeamId($organization->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        return [
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
