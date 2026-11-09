<?php

namespace App\Console\Commands;

use App\Jobs\PullFolder;
use App\Jobs\SyncClientDocuments;
use App\Models\Account;
use Illuminate\Console\Command;

class PullInbox extends Command
{
    protected $signature = 'inbox:pull';

    protected $description = 'Queue a pull of every watched Google Drive and Dropbox folder into its client\'s inbox, and a read of every reference folder';

    public function handle(): int
    {
        foreach (Account::whereIn('platform', [...array_keys(PullFolder::SOURCES), 'drive_docs'])->get() as $account) {
            if ($account->platform === 'drive_docs') {
                SyncClientDocuments::dispatch($account);
                $this->info("{$account->name}: documents queued");

                continue;
            }
            if (! isset($account->meta['folder'])) {
                continue; // a Dropbox connected without a folder yet
            }
            PullFolder::dispatch($account);
            $this->info("{$account->meta['folder_name']}: queued");
        }

        return self::SUCCESS;
    }
}
