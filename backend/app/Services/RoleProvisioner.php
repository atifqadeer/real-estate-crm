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
        'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.import',
        'leads.assign', 'leads.forms', 'leads.followups', 'leads.configure',
        'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete',
        'whatsapp.view', 'whatsapp.connect', 'whatsapp.send', 'whatsapp.templates',
        'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete', 'campaigns.spend',
        'analytics.view', 'analytics.export',
    ];

    public const ROLE_PERMISSIONS = [
        'super_admin' => '*',
        'agency_admin' => ['organizations.view', 'organizations.create', 'organizations.update', 'members.view', 'members.invite', 'members.update', 'members.remove', 'settings.view', 'settings.update', 'audit.view', 'dashboard.view'],
        'agency_marketing_manager' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'social.disconnect', 'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'media.delete', 'leads.view', 'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete', 'campaigns.spend', 'analytics.view', 'analytics.export'],
        'agency_marketing_executive' => ['organizations.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'content.view', 'content.create', 'content.edit', 'content.submit', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'leads.view', 'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.spend', 'analytics.view'],
        'agency_account_manager' => ['organizations.view', 'members.view', 'members.invite', 'settings.view', 'settings.update', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'content.view', 'content.create', 'content.edit', 'content.submit', 'content.approve', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'leads.view', 'leads.create', 'leads.edit', 'leads.assign', 'leads.followups', 'tasks.view', 'tasks.create', 'tasks.edit', 'whatsapp.view', 'whatsapp.connect', 'whatsapp.send', 'whatsapp.templates', 'campaigns.view', 'analytics.view', 'analytics.export'],
        'agency_analyst' => ['organizations.view', 'audit.view', 'dashboard.view', 'social.view', 'content.view', 'media.view', 'leads.view', 'campaigns.view', 'analytics.view', 'analytics.export'],
        'client_owner' => '*',
        'client_admin' => ['organizations.view', 'organizations.update', 'members.view', 'members.invite', 'members.update', 'members.remove', 'settings.view', 'settings.update', 'audit.view', 'dashboard.view', 'social.view', 'social.connect', 'social.test', 'social.disconnect', 'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit', 'content.approve', 'content.publish', 'content.schedule', 'media.view', 'media.upload', 'media.delete', 'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.import', 'leads.assign', 'leads.forms', 'leads.followups', 'leads.configure', 'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete', 'whatsapp.view', 'whatsapp.connect', 'whatsapp.send', 'whatsapp.templates', 'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete', 'campaigns.spend', 'analytics.view', 'analytics.export'],
        'client_marketing_approver' => ['organizations.view', 'members.view', 'dashboard.view', 'social.view', 'content.view', 'content.approve', 'media.view', 'whatsapp.view'],
        'client_sales_manager' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view', 'content.view', 'leads.view', 'leads.create', 'leads.edit', 'leads.delete', 'leads.import', 'leads.assign', 'leads.forms', 'leads.followups', 'leads.configure', 'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.delete', 'whatsapp.view', 'whatsapp.send', 'whatsapp.templates', 'campaigns.view', 'analytics.view'],
        'client_sales_agent' => ['organizations.view', 'dashboard.view', 'leads.view', 'leads.create', 'leads.edit', 'leads.followups', 'tasks.view', 'tasks.create', 'tasks.edit', 'whatsapp.view', 'whatsapp.send'],
        'client_readonly' => ['organizations.view', 'members.view', 'settings.view', 'dashboard.view', 'social.view', 'content.view', 'media.view', 'leads.view', 'campaigns.view', 'analytics.view'],
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
