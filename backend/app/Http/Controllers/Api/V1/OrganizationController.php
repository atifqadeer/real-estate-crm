<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\RoleProvisioner;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $orgs = $request->user()->activeMemberships()->orderBy('business_name')->get();
        return response()->json(['data' => $orgs]);
    }

    /** Agency staff with organizations.create (in an agency workspace) create client workspaces. */
    public function store(Request $request, RoleProvisioner $roles, AuditLogger $audit)
    {
        $user = $request->user();
        $agency = $user->activeMemberships()->where('type', 'agency')->get()
            ->first(fn (Organization $o) => in_array('organizations.create', $roles->contextFor($o, $user)['permissions'], true));
        abort_unless($agency, 403, 'Only agency staff with organizations.create may create client workspaces.');

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $client = DB::transaction(function () use ($data, $roles, $agency, $user) {
            $client = Organization::create(array_merge($data, [
                'type' => 'real_estate_client',
                'country' => 'Pakistan',
                'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                'currency' => $data['currency'] ?? 'PKR',
            ]));
            $client->settings()->create([]);
            $roles->provisionFor($client);
            // The creating agency staff member gets operational access as account manager.
            $client->users()->attach($user->id, ['status' => 'active', 'joined_at' => now()]);
            $roles->assign($client, $user, 'agency_account_manager');
            return $client;
        });

        $audit->log($client->id, $user->id, 'organization.created', ['type' => 'real_estate_client', 'via_agency' => $agency->id], $request, Organization::class, $client->id);

        return response()->json(['data' => $client], 201);
    }

    public function show(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);
        return response()->json(['data' => $organization]);
    }

    public function update(Request $request, Organization $organization, AuditLogger $audit)
    {
        $this->authorize('update', $organization);
        $data = $request->validate([
            'business_name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,suspended,archived'],
        ]);
        $organization->update($data);
        $audit->log($organization->id, $request->user()->id, 'organization.updated', ['fields' => array_keys($data)], $request, Organization::class, $organization->id);
        return response()->json(['data' => $organization->fresh()]);
    }

    public function destroy(Request $request, Organization $organization, AuditLogger $audit)
    {
        $this->authorize('delete', $organization);
        $organization->delete();
        $audit->log($organization->id, $request->user()->id, 'organization.deleted', [], $request, Organization::class, $organization->id);
        return response()->json(['data' => ['message' => 'Organization deleted (soft).']]);
    }
}
