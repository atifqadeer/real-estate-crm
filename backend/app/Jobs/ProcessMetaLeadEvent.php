<?php
namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Leads\MetaLeadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMetaLeadEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(MetaLeadService $service): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (!$event || $event->status === 'processed') return;
        $service->processEvent($event);
    }
}
