<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, RoleProvisioner $roles, AuditLogger $audit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'agency_name' => ['required', 'string', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'password' => $data['password'], 'is_agency_staff' => true,
            ]);
            $agency = Organization::create([
                'type' => 'agency', 'business_name' => $data['agency_name'],
                'contact_person' => $data['name'], 'email' => $data['email'],
            ]);
            $agency->settings()->create([]);
            $agency->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            $roles->provisionFor($agency);
            $roles->assign($agency, $user, 'super_admin');
            return [$user, $agency];
        });

        [$user, $agency] = $result;
        $audit->log($agency->id, $user->id, 'organization.created', ['type' => 'agency'], $request, Organization::class, $agency->id);
        $audit->log($agency->id, $user->id, 'auth.registered', [], $request);

        return response()->json([
            'data' => [
                'token' => $user->createToken('spa')->plainTextToken,
                'user' => $this->mePayload($user, $roles),
            ],
        ], 201);
    }

    public function login(Request $request, RoleProvisioner $roles, AuditLogger $audit)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log(null, $user->id, 'auth.login', [], $request);

        return response()->json([
            'data' => [
                'token' => $user->createToken('spa')->plainTextToken,
                'user' => $this->mePayload($user, $roles),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['data' => ['message' => 'Logged out.']]);
    }

    public function me(Request $request, RoleProvisioner $roles)
    {
        return response()->json(['data' => $this->mePayload($request->user(), $roles)]);
    }

    private function mePayload(User $user, RoleProvisioner $roles): array
    {
        $memberships = $user->activeMemberships()->get()->map(function (Organization $org) use ($user, $roles) {
            $ctx = $roles->contextFor($org, $user);
            return [
                'organization_id' => $org->id,
                'business_name' => $org->business_name,
                'type' => $org->type,
                'status' => $org->status,
                'roles' => $ctx['roles'],
                'permissions' => $ctx['permissions'],
            ];
        });

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_agency_staff' => (bool) $user->is_agency_staff,
            'memberships' => $memberships,
        ];
    }
}
