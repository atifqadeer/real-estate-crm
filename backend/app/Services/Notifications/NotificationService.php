<?php
namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Collection;

/**
 * In-app notifications (prompt section 17). Respects per-user,
 * per-organization disabled types. Email/WhatsApp delivery channels are
 * preference-gated extensions; the in-app record is the source of truth.
 */
class NotificationService
{
    /** Canonical notification types used across the product. */
    public const TYPES = [
        'lead.assigned', 'lead.created',
        'followup.due', 'followup.overdue',
        'content.approved', 'content.rejected', 'content.changes_requested',
        'content.published', 'content.failed',
        'social.disconnected', 'social.token_expired',
        'whatsapp.message', 'task.assigned',
    ];

    public function notify(Organization $organization, User|iterable $users, string $type, string $title, ?string $body = null, array $data = []): void
    {
        $users = $users instanceof User ? [$users] : $users;
        foreach ($users as $user) {
            if (!$user instanceof User) continue;
            if ($this->isDisabled($organization, $user, $type)) continue;
            InAppNotification::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data ?: null,
            ]);
        }
    }

    /** Active members of the org holding any of the given roles. */
    public function usersWithRoles(Organization $organization, array $roles): Collection
    {
        $ids = \Illuminate\Support\Facades\DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.organization_id', $organization->id)
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', $roles)
            ->pluck('model_has_roles.model_id')->unique();
        return $organization->users()->wherePivot('status', 'active')->whereIn('users.id', $ids)->get();
    }

    private function isDisabled(Organization $organization, User $user, string $type): bool
    {
        $pref = UserNotificationPreference::where('organization_id', $organization->id)
            ->where('user_id', $user->id)->first();
        return in_array($type, $pref?->disabled_types ?? [], true);
    }
}
