<?php

namespace App\Console\Commands;

use App\Alerts\Slack;
use App\Models\Post;
use App\Models\Report;
use App\Planning\ClaudeQueue;
use Illuminate\Console\Command;

/** Runs hourly: one Slack line per request the scheduled Claude worker has left for over two hours, once per request. */
class CheckClaudeQueue extends Command
{
    protected $signature = 'claude:check-queue';

    protected $description = 'Tell Slack once about each Claude request waiting for over 2 hours (is the scheduled task on?)';

    public function handle(): int
    {
        foreach (ClaudeQueue::late()->reject(fn (Post|Report $request) => ClaudeQueue::alerted($request)) as $request) {
            $at = ClaudeQueue::askedAt($request)->format('d-m-Y H:i');
            [$what, $url] = $request instanceof Report
                ? ["the plan request \"{$request->title}\"", route('clients.planner', $request->client_id)]
                : ["the captions of {$request->name}", route('posts.edit', $request)];
            $this->info($line = "{$request->client->name}: Claude has not picked up {$what}, queued {$at}. Check that the scheduled task is on.");
            Slack::send($line."\n".$url);
            ClaudeQueue::markAlerted($request);
        }

        return self::SUCCESS;
    }
}
