<?php

namespace App\Jobs;

use App\Alerts\Slack;
use App\Media\DocumentText;
use App\Media\GoogleDrive;
use App\Models\Account;
use App\Models\ClientDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Reads a client's reference folder on Google Drive (the drive_docs account) into client_documents: every document as text,
 * fetched again only when Drive says it changed, gone when the file is gone. One file's failure stays on its row.
 */
class SyncClientDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Account $account) {}

    /** One read per folder at a time: linking, "Read the folder now" and the hourly pull each queue one. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('documents:'.$this->account->id))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(GoogleDrive $drive): void
    {
        try {
            $files = $drive->documents($this->account);
        } catch (RuntimeException $e) {
            // the folder is no longer shared, or the key file is gone: the documents read so far stay, the Files tab says why
            $this->meta(['synced_at' => now()->toDateTimeString(), 'error' => $e->getMessage()]);

            return;
        }

        $existing = $this->account->documents()->get()->keyBy('source_id');
        foreach ($files as $file) {
            $document = $existing->get($file['id']) ?? $this->account->documents()->make(['source_id' => $file['id']])->forceFill(['client_id' => $this->account->client_id]);
            $modified = $file['modified'] ? Carbon::parse($file['modified'])->setTimezone(config('app.timezone')) : null; // Drive says UTC; the column is read in the app's zone
            $changed = ! $document->exists || ($document->modified_at?->toDateTimeString() !== $modified?->toDateTimeString()) || ($document->text === null && $document->error === null);
            $document->fill(['name' => $file['name'], 'path' => $file['path'], 'mime' => $file['mime'], 'modified_at' => $modified, 'size' => $file['size']]);
            if (! $document->kind_set_by_user) {
                $document->kind = ClientDocument::guessKind($file['name']);
            }
            if ($changed) {
                $this->fetch($drive, $document);
            }
            $document->save();
        }
        $this->account->documents()->whereNotIn('source_id', array_column($files, 'id'))->delete();

        $this->meta(['synced_at' => now()->toDateTimeString(), 'file_count' => count($files), 'error' => null]);
    }

    /** The file as text on its row, or the reason it could not be read; never an exception out of the loop. */
    private function fetch(GoogleDrive $drive, ClientDocument $document): void
    {
        try {
            // a Google Doc, Sheet or Slides file comes out as text or CSV: that is the type to read it as
            $native = GoogleDrive::isNative($document->mime);
            $bytes = $native ? $drive->export($document->source_id, $document->mime) : $drive->download($this->account, $document->source_id)->body();
            $text = DocumentText::extract($native ? GoogleDrive::EXPORTS[$document->mime] ?? $document->mime : $document->mime, $bytes, $document->name);
            $document->fill(['text' => $text, 'chars' => mb_strlen($text), 'error' => null]);
        } catch (Throwable $e) {
            $document->fill(['text' => null, 'chars' => 0, 'error' => $e->getMessage()]);
        }
        $document->fetched_at = now();
    }

    private function meta(array $values): void
    {
        $this->account->forceFill(['meta' => array_merge($this->account->meta ?? [], $values)])->save();
    }

    public function failed(?Throwable $e): void
    {
        $this->meta(['synced_at' => now()->toDateTimeString(), 'error' => $e?->getMessage() ?? 'unknown error']);
        Slack::send("{$this->account->client->name}: reading the reference folder \"{$this->account->name}\" failed: ".($e?->getMessage() ?? 'unknown error')."\n".route('clients.files', $this->account->client_id));
    }
}
