<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\Organization;
use App\Services\ContentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContentPostController extends Controller
{
    public function __construct(private ContentService $content) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.view'), 403);
        $posts = ContentPost::where('organization_id', $organization->id)
            ->with(['targets.account:id,display_name,username', 'author:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(20);
        return response()->json($posts);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.create'), 403);
        $post = $this->content->createPost($organization, $request->user(), $this->validated($request));
        return response()->json(['data' => $this->payload($post)], 201);
    }

    public function show(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.view'), 403);
        $post->load(['targets.account:id,display_name,username', 'media', 'approvals.actor:id,name', 'author:id,name']);
        return response()->json(['data' => $this->payload($post, detailed: true)]);
    }

    public function update(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.edit'), 403);
        $post = $this->content->updatePost($post, $this->validated($request));
        return response()->json(['data' => $this->payload($post)]);
    }

    public function destroy(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.delete'), 403);
        if (in_array($post->status, ['publishing', 'published', 'partially_published'], true)) {
            throw ValidationException::withMessages(['status' => 'Published posts cannot be deleted here.']);
        }
        $post->delete();
        return response()->json(['data' => ['message' => 'Post deleted.']]);
    }

    public function duplicate(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.create'), 403);
        $copy = $this->content->duplicate($post, $request->user());
        return response()->json(['data' => $this->payload($copy)], 201);
    }

    /** Eligible destination accounts + capabilities for the composer. */
    public function destinations(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.view'), 403);
        $data = collect($this->content->eligibleAccounts($organization))->map(fn ($row) => [
            'account' => [
                'id' => $row['account']->id,
                'platform' => $row['account']->platform->key,
                'display_name' => $row['account']->display_name,
                'username' => $row['account']->username,
                'status' => $row['account']->status,
            ],
            'eligible' => $row['eligible'],
            'reasons' => $row['reasons'],
            'capabilities' => $row['capabilities'],
        ]);
        return response()->json(['data' => $data]);
    }

    /** Per-destination preview: exactly what will be sent, plus validation errors. */
    public function previews(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.view'), 403);
        $post->load(['targets.account', 'media']);
        $previews = $post->targets->map(function (ContentPostTarget $t) use ($post) {
            $publisher = $this->content->publisherFor($t->platform);
            return [
                'target_id' => $t->id,
                'platform' => $t->platform,
                'account_name' => $t->account->display_name,
                'username' => $t->account->username,
                'text' => $post->effectiveText($t),
                'link_url' => $t->platform === 'facebook' ? $post->link_url : null,
                'media' => $post->media->map(fn ($m) => ['id' => $m->id, 'kind' => $m->kind, 'url' => $m->public_url, 'name' => $m->original_name])->values(),
                'errors' => $publisher->validateContent($post, $t),
            ];
        });
        return response()->json(['data' => $previews]);
    }

    public function submit(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.submit'), 403);
        return response()->json(['data' => $this->payload($this->content->submit($post, $request->user()))]);
    }

    public function approve(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.approve'), 403);
        $comment = $request->validate(['comment' => ['nullable', 'string']])['comment'] ?? null;
        return response()->json(['data' => $this->payload($this->content->approve($post, $request->user(), $comment))]);
    }

    public function reject(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.approve'), 403);
        $comment = $request->validate(['comment' => ['required', 'string']])['comment'];
        return response()->json(['data' => $this->payload($this->content->reject($post, $request->user(), $comment))]);
    }

    public function requestChanges(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.approve'), 403);
        $comment = $request->validate(['comment' => ['required', 'string']])['comment'];
        return response()->json(['data' => $this->payload($this->content->requestChanges($post, $request->user(), $comment))]);
    }

    public function publish(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.publish'), 403);
        $post = $this->content->publishNow($post, $request->user());
        $post->load('targets');
        return response()->json(['data' => $this->payload($post, detailed: true)]);
    }

    public function schedule(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.schedule'), 403);
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        $at = \Carbon\Carbon::parse($data['scheduled_at'])->utc();
        $post = $this->content->schedule($post, $at, $request->user());
        return response()->json(['data' => $this->payload($post)]);
    }

    public function cancelSchedule(Request $request, Organization $organization, ContentPost $post)
    {
        $this->guard($organization, $post);
        abort_unless($request->user()->can('content.schedule'), 403);
        return response()->json(['data' => $this->payload($this->content->cancelSchedule($post, $request->user()))]);
    }

    public function retryTarget(Request $request, Organization $organization, ContentPost $post, ContentPostTarget $target)
    {
        $this->guard($organization, $post);
        abort_unless($target->content_post_id === $post->id, 404);
        abort_unless($request->user()->can('content.publish'), 403);
        $this->content->retryTarget($target, $request->user());
        return response()->json(['data' => $this->payload($post->fresh(), detailed: true)]);
    }

    /** Calendar feed: posts with a scheduled or published date inside the range. */
    public function calendar(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('content.view'), 403);
        $from = $request->query('from') ? \Carbon\Carbon::parse($request->query('from')) : now()->startOfMonth();
        $to = $request->query('to') ? \Carbon\Carbon::parse($request->query('to')) : now()->endOfMonth();
        $posts = ContentPost::where('organization_id', $organization->id)
            ->with(['targets:id,content_post_id,platform,status,platform_url'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('scheduled_at', [$from, $to])->orWhereBetween('published_at', [$from, $to]);
            })
            ->orderBy('scheduled_at')->orderBy('published_at')->get();
        return response()->json(['data' => $posts]);
    }

    private function guard(Organization $organization, ContentPost $post): void
    {
        abort_unless($post->organization_id === $organization->id, 404);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'string'],
            'link_url' => ['nullable', 'url'],
            'cta_text' => ['nullable', 'string', 'max:200'],
            'first_comment' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'approval_required' => ['sometimes', 'boolean'],
            'media_ids' => ['sometimes', 'array'],
            'media_ids.*' => ['integer'],
            'targets' => ['sometimes', 'array'],
            'targets.*.social_account_id' => ['required', 'integer'],
            'targets.*.caption_override' => ['nullable', 'string'],
            'targets.*.hashtags_override' => ['nullable', 'string'],
        ]);
    }

    private function payload(ContentPost $post, bool $detailed = false): array
    {
        $base = [
            'id' => $post->id,
            'title' => $post->title,
            'caption' => $post->caption,
            'hashtags' => $post->hashtags,
            'link_url' => $post->link_url,
            'cta_text' => $post->cta_text,
            'first_comment' => $post->first_comment,
            'internal_notes' => $post->internal_notes,
            'status' => $post->status,
            'approval_required' => $post->approval_required,
            'scheduled_at' => $post->scheduled_at,
            'published_at' => $post->published_at,
            'author' => $post->author?->name,
            'targets' => $post->targets->map(fn (ContentPostTarget $t) => [
                'id' => $t->id,
                'social_account_id' => $t->social_account_id,
                'account_name' => $t->account?->display_name,
                'platform' => $t->platform,
                'status' => $t->status,
                'platform_post_id' => $t->platform_post_id,
                'platform_url' => $t->platform_url,
                'error_message' => $t->error_message,
                'caption_override' => $t->caption_override,
                'hashtags_override' => $t->hashtags_override,
            ])->values(),
            'media' => $post->relationLoaded('media') ? $post->media->map(fn ($m) => [
                'id' => $m->id, 'kind' => $m->kind, 'url' => $m->public_url, 'name' => $m->original_name,
            ])->values() : [],
        ];
        if ($detailed && $post->relationLoaded('approvals')) {
            $base['approvals'] = $post->approvals->map(fn ($a) => [
                'action' => $a->action, 'comment' => $a->comment, 'actor' => $a->actor?->name, 'at' => $a->created_at,
            ])->values();
        }
        return $base;
    }
}
