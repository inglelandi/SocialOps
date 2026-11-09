<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Planning\DraftBatch;
use App\Planning\PlanRequest;
use App\Planning\PlanWriter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class PlanContent extends Command
{
    protected $signature = 'content:plan
        {client : The client slug}
        {--posts=5 : How many posts to draft}
        {--platforms= : Comma-separated subset of the client accounts (default: all of them)}
        {--from= : First publishing date, YYYY-MM-DD (default: tomorrow)}
        {--days=14 : Length of the publishing window in days}
        {--brief= : Use this trend brief file (for example a /last30days report) instead of researching}
        {--latest-brief : Reuse the client\'s trend brief from the last 7 days instead of researching (researches when there is none)}
        {--instruction= : What the posts should be about; it decides the topic instead of the pillar quota}';

    protected $description = 'Research current trends and draft a batch of posts for a client as Draft posts on the calendar, ready for human review';

    public function handle(DraftBatch $batch, PlanWriter $writer): int
    {
        $client = Client::where('slug', $this->argument('client'))->firstOrFail();
        $profile = $client->toProfileArray();
        $from = CarbonImmutable::parse($this->option('from') ?? 'tomorrow');
        $request = new PlanRequest(
            profile: $profile,
            count: (int) $this->option('posts'),
            platforms: $this->option('platforms') ? explode(',', $this->option('platforms')) : PlanRequest::plannable($profile),
            from: $from,
            to: $from->addDays((int) $this->option('days') - 1),
            instruction: trim((string) $this->option('instruction')),
        );

        $brief = null;
        if ($file = $this->option('brief')) {
            $brief = is_file($file) ? file_get_contents($file) : throw new RuntimeException("No brief file at {$file}");
        } elseif ($this->option('latest-brief')) {
            $brief = $client->reports()->where('kind', 'trend_brief')->where('created_at', '>=', now()->subDays(7))->latest('id')->value('body');
        }

        $result = $batch->run($request, $brief, fn (string $step) => $this->line($step), $client->recentTopics());

        $sheet = $writer->write($client, $result, fileBrief: $brief === null);

        $this->table(
            ['Date', 'Platforms', 'Format', 'Pillar', 'Trend signal', 'Name'],
            array_map(fn ($p) => [$p['date'], implode(', ', $p['platforms']), $p['format'], $p['pillar'], $p['trend_signal'] ?? 'evergreen', $p['name']], $result['posts']),
        );
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        $this->info(count($result['posts']).' Draft posts created. Review sheet: '.route('reports.show', $sheet).'. Review before anything moves to In Review.');
        if (count($result['posts']) === 1) {
            $this->info('Post: '.$result['posts'][0]['url']);
        }

        return self::SUCCESS;
    }
}
