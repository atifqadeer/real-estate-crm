<?php
namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the requested organization against the user's ACTIVE
 * membership and installs it as the tenant context + Spatie team.
 * An organization id from the browser is a request, never proof.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $routeOrg = $request->route('organization');
        $organizationId = $routeOrg instanceof Organization ? $routeOrg->id : ($routeOrg ?? $request->header('X-Organization-Id'));
        abort_unless($organizationId, 400, 'Organization context is required.');

        $organization = Organization::query()->find((int) $organizationId);
        abort_unless($organization, 404);

        $membership = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->wherePivot('status', 'active')
            ->first();
        abort_unless($membership, 403, 'You are not an active member of this organization.');

        TenantContext::set($organization);
        setPermissionsTeamId($organization->id);
        // Roles are team-scoped: force re-resolution under this organization
        // in case the user instance carries relations from another context.
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
