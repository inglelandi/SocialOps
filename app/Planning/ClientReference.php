<?php

namespace App\Planning;

use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Support\Collection;

/**
 * What the planner reads from the client's reference folder on Google Drive (the Files tab): the posts the client published
 * before, so it does not repeat them, and the reference files, as facts. Two texts within a budget, plus the list of files.
 */
final class ClientReference
{
    /** Characters of previous posts in all, and per file; the newest files first. */
    public const PREVIOUS_TOTAL = 24000;

    public const PREVIOUS_EACH = 12000;

    public const REFERENCE_TOTAL = 16000;

    public const REFERENCE_EACH = 6000;

    /**
     * @return array{previous_posts: string, reference: string, files: list<array{name: string, path: string, kind: string, chars: int, modified: ?string, error: ?string}>, folder: ?array{name: string, synced_at: ?string}}
     */
    public static function for(Client $client): array
    {
        $folder = $client->accounts()->where('platform', 'drive_docs')->first();
        if (! $folder) {
            return ['previous_posts' => '', 'reference' => '', 'files' => [], 'folder' => null];
        }
        $documents = $folder->documents()->orderByRaw('modified_at is null')->orderByDesc('modified_at')->orderBy('name')->get();

        return [
            'previous_posts' => self::text($documents->where('kind', 'previous_posts'), self::PREVIOUS_TOTAL, self::PREVIOUS_EACH),
            'reference' => self::text($documents->where('kind', 'reference'), self::REFERENCE_TOTAL, self::REFERENCE_EACH),
            'files' => $documents->map(fn (ClientDocument $d) => ['name' => $d->name, 'path' => $d->path, 'kind' => $d->kind, 'chars' => $d->chars, 'modified' => $d->modified_at?->format('d-m-Y'), 'error' => $d->error])->values()->all(),
            'folder' => ['name' => $folder->name, 'synced_at' => $folder->meta['synced_at'] ?? null],
        ];
    }

    /** The readable files one after another under a "### name (date)" line, each and all within the budget, cut with a note. */
    private static function text(Collection $documents, int $total, int $each): string
    {
        $parts = [];
        $left = $total;
        foreach ($documents as $document) {
            if ($document->error !== null || $document->chars === 0 || $document->text === null) {
                continue;
            }
            if ($left <= 0) {
                break;
            }
            $limit = min($each, $left);
            $body = $document->text;
            if (mb_strlen($body) > $limit) {
                $body = rtrim(mb_substr($body, 0, $limit))."\n[… cut at {$limit} characters]";
            }
            $left -= min(mb_strlen($document->text), $limit);
            $parts[] = "### {$document->name} (".($document->modified_at?->format('d-m-Y') ?? '–').")\n{$body}";
        }

        return implode("\n\n", $parts);
    }
}
