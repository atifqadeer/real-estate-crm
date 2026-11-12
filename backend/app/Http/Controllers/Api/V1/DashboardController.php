<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('dashboard.view'), 403);

        $recent = AuditLog::where('organization_id', $organization->id)
            ->with('user:id,name')
            ->latest('created_at')->limit(10)->get()
            ->map(fn ($l) => [
                'action' => $l->action,
                'user' => $l->user?->name,
                'created_at' => $l->created_at,
            ]);

        return response()->json(['data' => [
            'organization' => [
                'id' => $organization->id,
                'business_name' => $organization->business_name,
                'type' => $organization->type,
                'city' => $organization->city,
                'currency' => $organization->currency,
                'timezone' => $organization->timezone,
            ],
            'members_count' => $organization->users()->wherePivot('status', 'active')->count(),
            'pending_invitations' => $organization->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            'recent_activity' => $recent,
            // Leads/posts/campaign metrics join here in Phases 2-6. Nothing is fabricated in Phase 1.
        ]]);
    }
}
