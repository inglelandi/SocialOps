<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Jobs\PlanContentBatch;
use App\Models\Client;
use App\Planning\ClaudeQueue;
use App\Planning\PlanRequest;
use App\Posts\PostState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $clients = $request->user()->clients()->withCount([
            'posts as week_count' => fn ($q) => $q->whereIn('state', [PostState::Approved, PostState::Scheduled])->whereBetween('scheduled_at', [now(), now()->addDays(7)]),
            'posts as in_review_count' => fn ($q) => $q->where('state', PostState::InReview),
            'posts as failed_count' => fn ($q) => $q->whereIn('state', [PostState::Failed, PostState::ManualNeeded]),
            'comments as open_comments_count' => fn ($q) => $q->open(),
            'accounts as expiring_count' => fn ($q) => $q->whereNotIn('platform', ['canva', 'dropbox'])->where('token_expires_at', '<', now()->addDays(7)),
        ])->withMax(['posts as scheduled_until' => fn ($q) => $q->whereIn('state', [PostState::Approved, PostState::Scheduled])], 'scheduled_at')
            ->reorder()->orderByRaw('plan_due is null')->orderBy('plan_due')->orderBy('name')->get(); // the soonest plan due first

        return view('clients.index', ['clients' => $clients]);
    }

    public function create(): View
    {
        return view('clients.create', ['client' => new Client]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        Client::create($request->clientAttributes());

        return redirect()->route('clients.index')->with('status', 'client-created');
    }

    public function edit(Client $client): View
    {
        $read = $client->documents()->where('chars', '>', 0)->whereIn('kind', ['previous_posts', 'reference']);

        return view('clients.edit', [
            'client' => $client,
            'accounts' => PlanRequest::accounts($client->toProfileArray()),
            'claudeJobs' => $client->reports()->where('kind', 'claude_job')->orderBy('id')->get(),
            // the reference folder of the Files tab, for the line under the MCP "Plan with Claude" box; null while none is linked
            'documents' => $client->accounts()->where('platform', 'drive_docs')->exists() ? [
                'read' => $read->count(),
                'previous' => $read->clone()->where('kind', 'previous_posts')->count(),
                'designs' => $client->documents()->where('kind', 'design')->whereNotNull('described_at')->count(),
                'waiting' => ClaudeQueue::designs()->where('client_id', $client->id)->count(),
            ] : null,
            'slideshow' => $client->accounts()->where('platform', 'drive_slides')->first(),
            'productCount' => $client->products()->count(),
        ]);
    }

    /** The Instagram grid as it will look: feed, carousel and Reel posts with a date, newest first, three across, with the first asset. */
    public function grid(Client $client): View
    {
        $posts = $client->posts()->with('assets')
            ->whereJsonContains('platforms', 'instagram')
            ->whereIn('format', ['feed', 'carousel', 'reel'])
            ->whereNotIn('state', [PostState::Idea, PostState::Withdrawn])
            ->whereNotNull('scheduled_at')
            ->orderByDesc('scheduled_at')
            ->limit(30)
            ->get();

        return view('clients.grid', ['client' => $client, 'posts' => $posts]);
    }

    /** "Plan with Claude": queue a content:plan run; the Drafts land on the calendar and the outcome is mailed. */
    public function plan(Request $request, Client $client): RedirectResponse
    {
        $options = $request->validate([
            'posts' => ['required', 'integer', 'min:1', 'max:'.PlanRequest::MAX_POSTS],
            'platforms' => ['required', 'array'],
            'platforms.*' => [Rule::in(PlanRequest::accounts($client->toProfileArray()))],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'days' => ['required', 'integer', 'min:1', 'max:92'],
            'brief' => ['nullable', 'string', 'max:50000'],
        ]);
        PlanContentBatch::dispatch($client, $request->user(), $options);

        return redirect()->route('clients.edit', $client)->with('status', 'plan-started');
    }

    /** "Post from a prompt": one Draft from the team's words, the profile and the trend brief, through the same queued content:plan run. */
    public function draft(Request $request, Client $client): RedirectResponse
    {
        $input = $request->validateWithBag('draft', [
            'instruction' => ['required', 'string', 'max:2000'],
            'platforms' => ['required', 'array'],
            'platforms.*' => [Rule::in(PlanRequest::accounts($client->toProfileArray()))],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'fresh' => ['nullable', 'boolean'],
            'brief' => ['nullable', 'string', 'max:50000'],
        ]);
        PlanContentBatch::dispatch($client, $request->user(), [
            'posts' => 1,
            'platforms' => $input['platforms'],
            'from' => $input['date'] ?? now()->addDay()->toDateString(),
            'days' => 1,
            'instruction' => $input['instruction'],
            'latest_brief' => ! ($input['fresh'] ?? false),
            'brief' => $input['brief'] ?? null,
        ]);

        return redirect()->route('clients.edit', $client)->with('status', 'post-started');
    }

    /** A plan request for the scheduled Claude worker (list-claude-jobs); save-plan removes it. The same request twice is queued once. */
    public function queueClaude(Request $request, Client $client): RedirectResponse
    {
        $fields = $request->validateWithBag('job', ['posts' => ['required', 'integer', 'min:1', 'max:'.PlanRequest::MAX_POSTS], 'from' => ['required', 'date_format:Y-m-d'], 'days' => ['required', 'integer', 'min:1', 'max:92'], 'instruction' => ['nullable', 'string', 'max:5000']]);
        if ($waiting = ClaudeQueue::same($client, $fields)->first()) {
            return redirect()->route('clients.edit', $client)->with('status', 'job-already-waiting')->with('queued_at', $waiting->created_at->format('d-m-Y H:i'));
        }
        $client->reports()->create(['kind' => 'claude_job', 'title' => __('Plan :posts posts from :from', $fields, $client->uiLocale()), 'body' => (string) ($fields['instruction'] ?? ''), 'meta' => $fields]);

        return redirect()->route('clients.edit', $client)->with('status', 'job-queued');
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->clientAttributes());

        return redirect()->route('clients.edit', $client)->with('status', 'client-updated');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $client->delete();

        return redirect()->route('clients.index')->with('status', 'client-deleted');
    }
}
