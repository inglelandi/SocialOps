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
 * fetched again only when Drive says it changed, every design as a row waiting for Claude's description (never downloaded),
 * gone when the file is gone. One file's failure stays on its row. A drive_slides account (the team's posts slideshow) is
 * one file, read the same way as kind upcoming_posts.
 */
class SyncClientDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** The size column's top (an unsigned int): a bigger video is kept at it. */
    public const SIZE_MAX = 4294967295;

    /** A design whose preview get-design could not fetch is offered again after this long, or as soon as the file changes. */
    public const RETRY_HOURS = 24;

    public function __construct(public Account $account) {}

    /** One read per folder at a time: linking, "Read the folder now" and the hourly pull each queue one. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('documents:'.$this->account->id))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(GoogleDrive $drive): void
    {
        $slideshow = $this->account->platform === 'drive_slides';
        try {
            $listing = $slideshow ? ['files' => [$drive->file($this->account->external_id)], 'capped' => false] : $drive->documents($this->account);
        } catch (RuntimeException $e) {
            // the folder or the slideshow is no longer shared, or the key file is gone: what was read so far stays, the Files tab says why
            $this->meta(['synced_at' => now()->toDateTimeString(), 'error' => $e->getMessage()]);

            return;
        }

        ['files' => $files, 'capped' => $capped] = $listing;
        // one row per file and client: the posts slideshow is read on its own, so the folder leaves it out even when it holds it
        if ($slideshow) {
            ClientDocument::where('client_id', $this->account->client_id)->where('source_id', $this->account->external_id)->whereNot('account_id', $this->account->id)->delete();
        } elseif ($skip = $this->account->client->accounts()->where('platform', 'drive_slides')->value('external_id')) {
            $files = array_values(array_filter($files, fn (array $file) => $file['id'] !== $skip));
        }
        $existing = $this->account->documents()->get()->keyBy('source_id');
        foreach ($files as $file) {
            $document = $existing->get($file['id']) ?? $this->account->documents()->make(['source_id' => $file['id']])->forceFill(['client_id' => $this->account->client_id]);
            $modified = $file['modified'] ? Carbon::parse($file['modified'])->setTimezone(config('app.timezone')) : null; // Drive says UTC; the column is read in the app's zone
            $fileChanged = ! $document->exists || $document->modified_at?->toDateTimeString() !== $modified?->toDateTimeString();
            $wasDesign = $document->kind === 'design' || $document->described_at !== null;
            // a video can be bigger than the column: kept at its top, still over the cap for a download
            $document->fill(['name' => $file['name'], 'path' => $file['path'], 'mime' => $file['mime'], 'modified_at' => $modified, 'size' => $file['size'] === null ? null : min($file['size'], self::SIZE_MAX)]);
            if ($slideshow) {
                $document->forceFill(['kind' => 'upcoming_posts', 'kind_set_by_user' => false]);
                if ($fileChanged || ($document->text === null && $document->error === null)) {
                    $this->fetch($drive, $document);
                }
            } elseif ($file['design']) {
                $this->design($document, $file['preview'], $fileChanged);
            } else {
                if (! $document->kind_set_by_user || $wasDesign) { // a design kind means nothing for a document
                    $document->forceFill(['kind' => ClientDocument::guessKind($file['name']), 'kind_set_by_user' => false]);
                }
                if ($fileChanged || $wasDesign || ($document->text === null && $document->error === null)) {
                    $document->described_at = null;
                    $this->fetch($drive, $document);
                }
            }
            $document->save();
        }
        $gone = $existing->keys()->diff(array_column($files, 'id'));
        foreach ($gone->chunk(500) as $chunk) {
            $this->account->documents()->whereIn('source_id', $chunk->values()->all())->delete();
        }

        $designs = count(array_filter($files, fn (array $file) => $file['design']));
        $this->meta(['synced_at' => now()->toDateTimeString(), 'file_count' => count($files) - $designs, 'design_count' => $designs, 'capped' => $capped, 'error' => null]);
    }

    /**
     * A design is never downloaded: it waits for Claude's description (list-claude-jobs), once. A described design stays as
     * it is until Drive says the file changed; then, like a new one, it waits again. Without a Drive preview it is never
     * offered, and the next sync looks again. A kind the team set (Not used) is kept.
     */
    private function design(ClientDocument $document, bool $preview, bool $fileChanged): void
    {
        if (! ($document->kind_set_by_user && $document->kind === 'ignored')) {
            $document->kind = 'design';
        }
        $waiting = ['text' => null, 'chars' => 0, 'described_at' => null, 'error' => null];
        if (! $fileChanged && $document->text !== null && $document->described_at !== null) {
            return; // described, and the file is the same: never offered again
        }
        if (! $preview) {
            $document->fill(['error' => ClientDocument::NO_PREVIEW] + $waiting);

            return;
        }
        $retry = $document->error === ClientDocument::PREVIEW_FAILED && ($document->fetched_at === null || $document->fetched_at->lt(now()->subHours(self::RETRY_HOURS)));
        $readBefore = $document->text !== null || ($document->error !== null && $document->error !== ClientDocument::PREVIEW_FAILED); // a PDF read as a document before, or no preview until now
        if ($fileChanged || $retry || $readBefore) {
            $document->fill($waiting);
        }
    }

    /** The file as text on its row, or the reason it could not be read; never an exception out of the loop. */
    private function fetch(GoogleDrive $drive, ClientDocument $document): void
    {
        try {
            // a Google Doc, Sheet or Slides file comes out as text or CSV: that is the type to read it as
            $native = GoogleDrive::isNative($document->mime);
            throw_if(! $native && $document->size > DocumentText::MAX_BYTES, new RuntimeException(DocumentText::TOO_BIG));
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
        $what = $this->account->platform === 'drive_slides' ? 'the posts slideshow' : 'the reference folder';
        Slack::send("{$this->account->client->name}: reading {$what} \"{$this->account->name}\" failed: ".($e?->getMessage() ?? 'unknown error')."\n".route('clients.files', $this->account->client_id));
    }
}
