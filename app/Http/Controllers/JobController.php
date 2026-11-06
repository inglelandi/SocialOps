<?php

namespace App\Http\Controllers;

use App\Models\ClaudeCall;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Planning\ClaudeQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/** What waits for the scheduled Claude worker, the queue (waiting and failed jobs) and what Claude cost in the last 30 days. */
class JobController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $name = fn ($job) => json_decode($job->payload, true)['displayName'] ?? '?';
        // a failed Claude call stores the SDK's JSON dump under its first line; show the API's own message instead
        $error = fn ($text) => preg_match('/^Anthropic\\\\[^\n]*\n[\s\S]{0,400}?"message": "((?:[^"\\\\]|\\\\.)*)"/', $text, $m) ? 'Claude: '.json_decode('"'.$m[1].'"') : strtok($text, "\n");
        // every section holds the login's clients only; a limited login does not see a job whose client cannot be told
        $visible = fn ($job) => $user->client_ids === null || (($client = self::clientOf($job->payload)) !== null && $user->canSee($client));

        return view('jobs.index', [
            'claude' => ClaudeQueue::all($user),
            'waiting' => DB::table('jobs')->orderBy('id')->get()->filter($visible)->values()->map(fn ($job) => (object) ['name' => $name($job), 'attempts' => $job->attempts, 'running' => (bool) $job->reserved_at, 'at' => date('Y-m-d H:i', $job->available_at)]),
            'failed' => DB::table('failed_jobs')->latest('failed_at')->limit($user->client_ids === null ? 50 : 500)->get()->filter($visible)->take(50)->values()->map(fn ($job) => (object) ['uuid' => $job->uuid, 'name' => $name($job), 'error' => $error($job->exception), 'at' => $job->failed_at]),
            'usage' => $user->restrict(ClaudeCall::query())->with('client')->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('client_id, purpose, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
                ->groupBy('client_id', 'purpose')->orderBy('client_id')->orderBy('purpose')->get(),
        ]);
    }

    /**
     * The client a queued job works for, read from the models it carries (a client, or a post, account, review… of one);
     * null when it carries none.
     */
    public static function clientOf(string $payload): ?int
    {
        static $columns = [];
        $command = (string) (json_decode($payload, true)['data']['command'] ?? '');
        // a model in a queued job is serialized as a ModelIdentifier: its class, then its key
        preg_match_all('/s:5:"class";s:\d+:"([^"]+)";s:2:"id";(?:i:(\d+)|s:\d+:"(\d+)")/', $command, $models, PREG_SET_ORDER);
        foreach ($models as $model) {
            [$class, $id] = [$model[1], (int) (($model[2] ?? '') !== '' ? $model[2] : ($model[3] ?? 0))];
            if ($class === Client::class) {
                return $id;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            $table = (new $class)->getTable();
            if ($columns[$table] ??= Schema::hasColumn($table, 'client_id')) {
                $client = $class::query()->withoutGlobalScopes()->whereKey($id)->value('client_id');
                if ($client !== null) {
                    return (int) $client;
                }
            }
        }

        return null;
    }

    /** Cancel a plan request from the client page or the Jobs page (LimitClients checks the report's client). */
    public function cancelPlan(Report $report): RedirectResponse
    {
        abort_unless($report->kind === 'claude_job', 404);
        $report->delete();

        return back(fallback: route('jobs.index'))->with('status', 'plan-request-cancelled');
    }

    /** "Stop asking": untick "Ask Claude to write the captions" on the post. */
    public function stopAsking(Post $post): RedirectResponse
    {
        ClaudeQueue::stopAsking($post);

        return back(fallback: route('jobs.index'))->with('status', 'caption-request-cancelled');
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        $this->mayTouch($request, $uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return redirect()->route('jobs.index')->with('status', 'job-queued-again');
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        $this->mayTouch($request, $uuid);
        Artisan::call('queue:forget', ['id' => $uuid]);

        return redirect()->route('jobs.index')->with('status', 'job-removed');
    }

    /** A limited login retries or removes a failed job of its own clients only. */
    private function mayTouch(Request $request, string $uuid): void
    {
        $user = $request->user();
        if ($user->client_ids === null) {
            return;
        }
        $payload = DB::table('failed_jobs')->where('uuid', $uuid)->value('payload');
        $client = $payload === null ? null : self::clientOf($payload);
        abort_unless($client !== null && $user->canSee($client), 403, __('This login is limited to other clients.'));
    }
}
