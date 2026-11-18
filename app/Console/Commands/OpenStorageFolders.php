<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Opens the stored media for the server's other users. Until `directory_visibility` was set on the local disk, Laravel made
 * every folder it created 0700 (the clients' clients/<slug>/inbox among them), so a backup running as another user could
 * not enter them (Panos, 2026-11-18: "the clients folder does not have any permissions… issues on the server when it tries
 * to take backup"). Folders get read and enter for group and others (0755), files read (0644); nothing is ever taken away.
 * Only what this process owns can be changed: the rest is counted and named. /refresh runs it after a pull.
 */
class OpenStorageFolders extends Command
{
    protected $signature = 'storage:open-folders {--path=* : The folders to walk (default: storage/app)}';

    protected $description = 'Make the stored media folders readable for the server backup (folders 0755, files 0644)';

    /** At most this many paths that could not be opened are named. */
    public const NAMED = 10;

    public function handle(): int
    {
        $result = self::open($this->option('path') ?: [storage_path('app')]);
        $this->info("Opened {$result['opened']} folders and files.");
        if ($result['closed'] !== []) {
            $this->warn(count($result['closed']).' belong to another server user and stay closed, e.g. '.implode(', ', array_slice($result['closed'], 0, self::NAMED)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $roots
     * @return array{opened: int, closed: list<string>}
     */
    public static function open(array $roots): array
    {
        $opened = 0;
        $closed = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST, // a folder is opened before it is walked into
                RecursiveIteratorIterator::CATCH_GET_CHILD, // a folder that stays closed is skipped, not fatal
            );
            try {
                foreach ([new SplFileInfo($root), ...$items] as $item) {
                    if ($item->isLink()) {
                        continue;
                    }
                    $need = $item->isDir() ? 0055 : 0044; // read (and enter) for the group and the others
                    $mode = fileperms($item->getPathname()) & 0777;
                    if (($mode & $need) === $need) {
                        continue;
                    }
                    if (@chmod($item->getPathname(), $mode | $need)) {
                        $opened++;
                    } else {
                        $closed[] = $item->getPathname();
                    }
                }
            } catch (UnexpectedValueException) {
                $closed[] = $root;
            }
            clearstatcache();
        }

        return ['opened' => $opened, 'closed' => $closed];
    }
}
