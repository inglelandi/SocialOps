<?php

namespace App\Mcp\Tools;

use App\Models\ClientDocument;
use App\Models\Post;
use App\Models\Report;
use App\Planning\ClaudeQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('The work the team queued for Claude in the app: posts ticked "Ask Claude for the captions", plan requests from the client page, and designs from the clients\' Drive folders still to be described (at most 25 per call, oldest first; designs_waiting counts them per client). Do each one as its "how" says, which also clears it from the queue. When all three lists are empty ({"captions":[],"plans":[],"designs":[]}) there is nothing to do: stop without a reply. Meant for the scheduled worker; nothing here publishes.')]
class ListClaudeJobs extends Tool
{
    use ScopedToToken;

    public function handle(Request $request): Response
    {
        $clients = $this->clients()->select('id');
        $designs = ClaudeQueue::designs()->whereIn('client_id', $clients);

        $jobs = [
            'captions' => ClaudeQueue::captions()->with('client')->whereIn('client_id', $clients)->orderBy('id')->get()
                ->map(fn (Post $p) => ['post' => $p->id, 'client' => $p->client->slug, 'name' => $p->name, 'url' => route('posts.edit', $p), 'how' => 'get-caption-brief, write, then update-post with the variants, settings.design and settings {ask_claude: false}'])->values(),
            'plans' => ClaudeQueue::plans()->with('client')->whereIn('client_id', $clients)->orderBy('id')->get()
                ->map(fn (Report $r) => ['job' => $r->id, 'client' => $r->client->slug] + ClaudeQueue::request($r) + ['how' => "get-planning-brief with these fields, research the trends, plan the posts and write every post's captions as the brief's caption_rules say, then save-plan with job {$r->id} and the captions inside each post; write any post it lists under captions_missing with get-caption-brief and update-post"])->values(),
            // the archive in date order: the oldest designs first, a batch per call across every client in reach
            'designs' => $designs->clone()->with('client')->orderByRaw('modified_at is null')->orderBy('modified_at')->orderBy('id')->limit(ClaudeQueue::DESIGNS_PER_RUN)->get()
                ->map(fn (ClientDocument $d) => ['document' => $d->id, 'client' => $d->client->slug, 'name' => $d->name, 'path' => $d->path, 'modified' => $d->modified_at?->toDateString(), 'how' => "get-design, look at it, then save-design-description with document {$d->id}"])->values(),
        ];
        if ($jobs['designs']->isNotEmpty()) {
            $slugs = $this->clients()->pluck('slug', 'id');
            $jobs['designs_waiting'] = $designs->clone()->selectRaw('client_id, count(*) as waiting')->groupBy('client_id')->pluck('waiting', 'client_id')
                ->mapWithKeys(fn ($waiting, $id) => [$slugs[$id] => (int) $waiting])->sortKeys();
        }

        return Response::json($jobs);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
