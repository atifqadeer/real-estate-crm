<?php
namespace App\Jobs;

use App\Models\LeadFollowUp;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly sweep: notify owners about follow-ups due within the hour and
 * follow-ups that have gone overdue. Each follow-up notifies once per kind
 * (due_notified_at / overdue_notified_at markers on the row).
 */
class CheckFollowUpNotifications implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notifications): void
    {
        $dueSoon = LeadFollowUp::withoutTenantScope()
            ->where('status', 'pending')->whereNull('due_notified_at')
            ->where('due_at', '<=', now()->addHour())->where('due_at', '>=', now())
            ->with(['lead', 'user'])->get();
        foreach ($dueSoon as $fu) {
            if ($fu->user && $fu->lead) {
                $notifications->notify($fu->lead->organization, $fu->user, 'followup.due',
                    'Follow-up due: ' . $fu->lead->full_name,
                    'Due ' . $fu->due_at->toDateTimeString() . ($fu->note ? " — {$fu->note}" : ''),
                    ['lead_id' => $fu->lead_id, 'follow_up_id' => $fu->id]);
            }
            $fu->update(['due_notified_at' => now()]);
        }

        $overdue = LeadFollowUp::withoutTenantScope()
            ->where('status', 'pending')->whereNull('overdue_notified_at')
            ->where('due_at', '<', now())
            ->with(['lead', 'user'])->get();
        foreach ($overdue as $fu) {
            if ($fu->user && $fu->lead) {
                $notifications->notify($fu->lead->organization, $fu->user, 'followup.overdue',
                    'Follow-up OVERDUE: ' . $fu->lead->full_name,
                    'Was due ' . $fu->due_at->toDateTimeString(),
                    ['lead_id' => $fu->lead_id, 'follow_up_id' => $fu->id]);
            }
            $fu->update(['overdue_notified_at' => now()]);
        }
    }
}
