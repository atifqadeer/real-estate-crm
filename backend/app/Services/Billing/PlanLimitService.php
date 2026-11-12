<?php
namespace App\Services\Billing;

use App\Models\ClientSubscription;
use App\Models\ContentPost;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SocialAccount;
use Illuminate\Validation\ValidationException;

/**
 * Subscription packages & plan limits (prompt sections 4/18, Phase 7).
 * Enforcement philosophy: limits apply ONLY when the workspace has a
 * current subscription whose package defines that limit. No package
 * (or a limit left null) means unlimited — agency-managed workspaces
 * are never blocked by surprise.
 */
class PlanLimitService
{
    public const KEYS = ['max_users', 'max_social_accounts', 'max_leads_per_month', 'max_posts_per_month'];

    public function currentSubscription(Organization $organization): ?ClientSubscription
    {
        return ClientSubscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->latest()->first()?->load('package');
    }

    public function limits(Organization $organization): array
    {
        $subscription = $this->currentSubscription($organization);
        $limits = $subscription?->package?->limits ?? [];
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = isset($limits[$key]) && $limits[$key] !== null ? (int) $limits[$key] : null;
        }
        return $out;
    }

    public function usage(Organization $organization): array
    {
        return [
            'max_users' => $organization->users()->wherePivot('status', 'active')->count(),
            'max_social_accounts' => SocialAccount::withoutTenantScope()
                ->where('organization_id', $organization->id)->where('status', 'connected')->count(),
            'max_leads_per_month' => Lead::withoutTenantScope()->where('organization_id', $organization->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'max_posts_per_month' => ContentPost::withoutTenantScope()->where('organization_id', $organization->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
    }

    /** Throws a 422 ValidationException when the plan limit is reached. */
    public function assertCan(Organization $organization, string $key): void
    {
        $limit = $this->limits($organization)[$key] ?? null;
        if ($limit === null) return;
        $used = $this->usage($organization)[$key] ?? 0;
        if ($used >= $limit) {
            $label = str_replace('_', ' ', $key);
            throw ValidationException::withMessages(['plan' =>
                "Plan limit reached: {$label} is capped at {$limit} on the current package (currently {$used}). Upgrade the package to continue."]);
        }
    }

    public function summary(Organization $organization): array
    {
        $subscription = $this->currentSubscription($organization);
        return [
            'subscription' => $subscription,
            'limits' => $this->limits($organization),
            'usage' => $this->usage($organization),
        ];
    }
}
