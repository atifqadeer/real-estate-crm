<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\Organization;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;

class LeadTaskController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('tasks.view'), 403);
        $tasks = LeadTask::where('organization_id', $organization->id)
            ->with(['lead:id,full_name', 'assignee:id,name'])
            ->when($request->query('scope') === 'mine', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('lead_id'), fn ($q, $v) => $q->where('lead_id', $v))
            ->orderByRaw("status = 'pending' desc")->orderBy('due_at')->paginate(30);
        return response()->json($tasks);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('tasks.create'), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'lead_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,normal,high'],
        ]);
        if (!empty($data['lead_id'])) {
            Lead::where('organization_id', $organization->id)->findOrFail($data['lead_id']);
        }
        $task = LeadTask::create(array_merge($data, [
            'organization_id' => $organization->id,
            'created_by' => $request->user()->id,
            'assigned_to' => $data['assigned_to'] ?? $request->user()->id,
        ]));
        $assignee = $task->assignee;
        if ($assignee && $assignee->id !== $request->user()->id) {
            $this->notifications->notify($organization, $assignee, 'task.assigned',
                'New task: ' . $task->title, $task->due_at ? 'Due ' . $task->due_at->toDateTimeString() : null,
                ['task_id' => $task->id, 'lead_id' => $task->lead_id]);
        }
        return response()->json(['data' => $task->load(['lead:id,full_name', 'assignee:id,name'])], 201);
    }

    public function update(Request $request, Organization $organization, LeadTask $task)
    {
        $this->guard($organization, $task);
        abort_unless($request->user()->can('tasks.edit'), 403);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,done,cancelled'],
            'priority' => ['nullable', 'in:low,normal,high'],
        ]);
        if (($data['status'] ?? null) === 'done') $data['completed_at'] = now();
        $task->update($data);
        return response()->json(['data' => $task->fresh()->load(['lead:id,full_name', 'assignee:id,name'])]);
    }

    public function destroy(Request $request, Organization $organization, LeadTask $task)
    {
        $this->guard($organization, $task);
        abort_unless($request->user()->can('tasks.delete'), 403);
        $task->delete();
        return response()->json(['data' => ['message' => 'Task deleted.']]);
    }

    private function guard(Organization $organization, LeadTask $task): void
    {
        abort_unless($task->organization_id === $organization->id, 404);
    }
}
