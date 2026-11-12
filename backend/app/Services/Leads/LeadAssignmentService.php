<?php
namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignmentRule;
use App\Models\Organization;
use App\Models\User;

/**
 * Assignment order: explicit rules (city -> property type -> source ->
 * default), then organization round-robin over active sales members.
 * Every assignment is written to the lead's activity history.
 */
class LeadAssignmentService
{
    public function autoAssign(Lead $lead, ?User $actor = null): ?User
    {
        $org = $lead->organization;
        $rules = LeadAssignmentRule::withoutTenantScope()
            ->where('organization_id', $org->id)->where('is_active', true)
            ->orderBy('sort_order')->get();

        foreach (['city', 'property_type', 'source', 'default'] as $type) {
            foreach ($rules->where('match_type', $type) as $rule) {
                if ($this->matches($rule, $lead)) {
                    return $this->apply($lead, $rule->assignee, $actor, "rule:{$type}");
                }
            }
        }

        $mode = $org->settings()->first()?->settings['lead.assignment_mode'] ?? 'round_robin';
        if ($mode === 'round_robin') {
            $agent = $this->nextRoundRobinAgent($org);
            if ($agent) return $this->apply($lead, $agent, $actor, 'round_robin');
        }
        return null;
    }

    public function manualAssign(Lead $lead, User $agent, ?User $actor = null): void
    {
        $this->apply($lead, $agent, $actor, 'manual');
    }

    private function matches(LeadAssignmentRule $rule, Lead $lead): bool
    {
        return match ($rule->match_type) {
            'city' => $rule->match_value && (
                strcasecmp((string) $lead->city, $rule->match_value) === 0 ||
                str_contains(strtolower((string) $lead->preferred_location), strtolower($rule->match_value))
            ),
            'property_type' => $rule->match_value && strcasecmp((string) $lead->property_type, $rule->match_value) === 0,
            'source' => $rule->match_value && ($lead->source?->key === $rule->match_value),
            'default' => true,
            default => false,
        };
    }

    private function nextRoundRobinAgent(Organization $org): ?User
    {
        // Read role assignments straight from Spatie's tables so this also
        // works outside an HTTP request (queue jobs have no tenant team set).
        $salesUserIds = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.organization_id', $org->id)
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', ['client_sales_agent', 'client_sales_manager'])
            ->pluck('model_has_roles.model_id')->unique();

        $members = $org->users()->wherePivot('status', 'active')
            ->whereIn('users.id', $salesUserIds)->orderBy('users.id')->get()->values();
        if ($members->isEmpty()) return null;

        $setting = $org->settings()->first();
        $pointer = $setting?->settings['lead.rr_last_user_id'] ?? null;
        $idx = $members->search(fn (User $u) => $u->id === (int) $pointer);
        $next = $members[(($idx === false ? -1 : $idx) + 1) % $members->count()];

        if ($setting) {
            $settings = $setting->settings ?? [];
            $settings['lead.rr_last_user_id'] = $next->id;
            $setting->update(['settings' => $settings]);
        }
        return $next;
    }

    private function apply(Lead $lead, ?User $agent, ?User $actor, string $via): ?User
    {
        if (!$agent) return null;
        $previous = $lead->assigned_to;
        $lead->update(['assigned_to' => $agent->id]);
        LeadActivity::create([
            'organization_id' => $lead->organization_id,
            'lead_id' => $lead->id,
            'actor_id' => $actor?->id,
            'type' => $previous ? 'reassigned' : 'assigned',
            'description' => "Assigned to {$agent->name} ({$via}).",
            'metadata' => ['via' => $via, 'from' => $previous, 'to' => $agent->id],
        ]);
        if ($actor?->id !== $agent->id && $lead->organization) {
            app(\App\Services\Notifications\NotificationService::class)->notify(
                $lead->organization, $agent, 'lead.assigned',
                ($previous ? 'Lead reassigned to you: ' : 'New lead assigned to you: ') . $lead->full_name,
                trim(($lead->city ?? '') . ' ' . ($lead->phone_e164 ?? '')),
                ['lead_id' => $lead->id, 'via' => $via]
            );
        }
        return $agent;
    }
}
