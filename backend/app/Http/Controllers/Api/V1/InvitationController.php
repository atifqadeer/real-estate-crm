<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function store(Request $request, Organization $organization, AuditLogger $audit)
    {
        abort_unless($request->user()->can('members.invite'), 403);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'in:' . implode(',', array_keys(RoleProvisioner::ROLE_PERMISSIONS))],
        ]);

        $plainToken = Str::random(48);
        $invitation = Invitation::withoutTenantScope()->create([
            'organization_id' => $organization->id,
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'token_hash' => hash('sha256', $plainToken),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        $audit->log($organization->id, $request->user()->id, 'member.invited', ['email' => $invitation->email, 'role' => $invitation->role], $request, Invitation::class, $invitation->id);

        $payload = ['invitation' => $invitation];
        if (app()->environment('local', 'testing')) {
            // Dev convenience only: in production the token travels by email, never by API.
            $payload['invite_token'] = $plainToken;
        }
        return response()->json(['data' => $payload], 201);
    }

    public function accept(Request $request, string $token, RoleProvisioner $roles, AuditLogger $audit)
    {
        $invitation = Invitation::withoutTenantScope()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->first();
        abort_unless($invitation, 404, 'Invitation not found or already used.');
        abort_if($invitation->isExpired(), 410, 'Invitation has expired.');

        $data = $request->validate([
            'name' => ['required_without:user_id', 'nullable', 'string', 'max:255'],
            'password' => ['required_without:user_id', 'nullable', 'string', 'min:8'],
        ]);

        $user = DB::transaction(function () use ($invitation, $data, $roles) {
            $user = User::where('email', $invitation->email)->first();
            if (!$user) {
                abort_unless(!empty($data['name']) && !empty($data['password']), 422, 'Name and password are required to create your account.');
                $user = User::create([
                    'name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password'],
                ]);
            }
            $organization = Organization::findOrFail($invitation->organization_id);
            $organization->users()->syncWithoutDetaching([$user->id => ['status' => 'active', 'joined_at' => now()]]);
            $roles->provisionFor($organization);
            $roles->assign($organization, $user, $invitation->role);
            $invitation->forceFill(['accepted_at' => now()])->save();
            return $user;
        });

        $audit->log($invitation->organization_id, $user->id, 'member.invitation_accepted', ['email' => $invitation->email], $request, Invitation::class, $invitation->id);

        return response()->json([
            'data' => [
                'token' => $user->createToken('spa')->plainTextToken,
                'user_id' => $user->id,
            ],
        ]);
    }
}
