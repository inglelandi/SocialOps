<?php

namespace App\Console\Commands;

use App\Jobs\SyncProductFeed;
use App\Models\Account;
use Illuminate\Console\Command;

class PullProducts extends Command
{
    protected $signature = 'products:pull';

    protected $description = 'Queue a read of every client\'s product feed, so the products Claude links stay as the shop lists them';

    public function handle(): int
    {
        foreach (Account::where('platform', 'product_feed')->get() as $feed) {
            SyncProductFeed::dispatch($feed);
            $this->info("{$feed->name}: product feed queued");
        }

        return self::SUCCESS;
    }
}
