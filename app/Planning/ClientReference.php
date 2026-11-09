<?php

namespace App\Planning;

use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Support\Collection;

/**
 * What the planner reads from the client's reference folder on Google Drive (the Files tab): the posts the client published
 * before, so it does not repeat them, the reference files, as facts, and the designs Claude described from their pictures
 * (the posts the client published, seen from their graphics). Three texts within a budget, plus the list of documents.
 */
final class ClientReference
{
    /** Characters of previous posts in all, and per file; the newest files first. */
    public const PREVIOUS_TOTAL = 24000;

    public const PREVIOUS_EACH = 12000;

    public const REFERENCE_TOTAL = 16000;

    public const REFERENCE_EACH = 6000;

    /** Characters of design descriptions in all, and per design; the newest Drive date first. */
    public const DESIGNS_TOTAL = 16000;

    public const DESIGNS_EACH = ClientDocument::DESCRIPTION_MAX;

    /**
     * @return array{previous_posts: string, reference: string, designs: string, designs_described: int, designs_waiting: int, files: list<array{name: string, path: string, kind: string, chars: int, modified: ?string, error: ?string}>, folder: ?array{name: string, synced_at: ?string}}
     */
    public static function for(Client $client): array
    {
        $folder = $client->accounts()->where('platform', 'drive_docs')->first();
        if (! $folder) {
            return ['previous_posts' => '', 'reference' => '', 'designs' => '', 'designs_described' => 0, 'designs_waiting' => 0, 'files' => [], 'folder' => null];
        }
        $all = $folder->documents()->orderByRaw('modified_at is null')->orderByDesc('modified_at')->orderBy('name')->get();
        [$designs, $documents] = $all->partition(fn (ClientDocument $d) => $d->isDesign());
        $described = $designs->filter(fn (ClientDocument $d) => $d->kind === 'design' && $d->text !== null && $d->described_at !== null)->values();

        return [
            'previous_posts' => self::text($documents->where('kind', 'previous_posts'), self::PREVIOUS_TOTAL, self::PREVIOUS_EACH),
            'reference' => self::text($documents->where('kind', 'reference'), self::REFERENCE_TOTAL, self::REFERENCE_EACH),
            'designs' => self::designs($described),
            'designs_described' => $described->count(),
            'designs_waiting' => $designs->filter(fn (ClientDocument $d) => $d->isWaiting())->count(),
            // the documents only: a folder can hold hundreds of designs, counted above
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

    /** One line per described design, newest first: "- DD-MM-YYYY · name: the description on one line", within DESIGNS_TOTAL, with a count of the ones left out. */
    private static function designs(Collection $described): string
    {
        $lines = [];
        $left = self::DESIGNS_TOTAL;
        foreach ($described as $i => $design) {
            $description = trim(preg_replace('/\s*\n\s*/', ' · ', mb_substr($design->text, 0, self::DESIGNS_EACH)));
            $line = '- '.($design->modified_at?->format('d-m-Y') ?? '–')." · {$design->name}: {$description}";
            if (mb_strlen($line) + 1 > $left) {
                $lines[] = '[… '.($described->count() - $i).' older described designs left out to keep the brief short]';
                break;
            }
            $lines[] = $line;
            $left -= mb_strlen($line) + 1;
        }

        return implode("\n", $lines);
    }
}
