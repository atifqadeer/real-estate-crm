<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleProvisioner;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request, Organization $organization, RoleProvisioner $roles)
    {
        abort_unless($request->user()->can('members.view'), 403);
        $rows = $organization->users()->withPivot(['status', 'joined_at'])->get()->map(function (User $u) use ($organization, $roles) {
            return [
                'id' => $u->pivot->id ?? null,
                'status' => $u->pivot->status,
                'roles' => $roles->contextFor($organization, $u)['roles'],
                'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email],
            ];
        });
        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, Organization $organization, User $member, RoleProvisioner $roles, AuditLogger $audit)
    {
        abort_unless($request->user()->can('members.update'), 403);
        abort_unless($organization->users()->whereKey($member->id)->exists(), 404);

        $data = $request->validate([
            'role' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        if (isset($data['role'])) {
            $roles->assign($organization, $member, $data['role']);
            $audit->log($organization->id, $request->user()->id, 'member.role_changed', ['member' => $member->id, 'role' => $data['role']], $request, User::class, $member->id);
        }
        if (isset($data['status'])) {
            $organization->users()->updateExistingPivot($member->id, ['status' => $data['status']]);
            $audit->log($organization->id, $request->user()->id, 'member.status_changed', ['member' => $member->id, 'status' => $data['status']], $request, User::class, $member->id);
        }

        return response()->json(['data' => ['message' => 'Member updated.']]);
    }

    public function destroy(Request $request, Organization $organization, User $member, RoleProvisioner $roles, AuditLogger $audit)
    {
        abort_unless($request->user()->can('members.remove'), 403);
        abort_if($request->user()->id === $member->id, 422, 'You cannot remove yourself.');
        abort_unless($organization->users()->whereKey($member->id)->exists(), 404);

        setPermissionsTeamId($organization->id);
        $member->roles()->wherePivot('organization_id', $organization->id)->detach();
        $organization->users()->detach($member->id);
        $audit->log($organization->id, $request->user()->id, 'member.removed', ['member' => $member->id], $request, User::class, $member->id);

        return response()->json(['data' => ['message' => 'Member removed.']]);
    }
}
