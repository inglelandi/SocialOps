<?php

namespace App\Mcp\Tools;

use App\Models\ClientDocument;
use App\Planning\ClaudeQueue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Step 2 of describing a design (after get-design): saves your description of the post the design shows, once; the design leaves the queue and the planning brief of that client carries it from now on. Describe only what is visible: what it shows, the exact text on it, the offer or event, the season; never invent. Write in the client\'s language as get-design says (Greek by default). At most 1500 characters with the fields. Nothing is published.')]
class SaveDesignDescription extends Tool
{
    use ScopedToToken;

    public function handle(Request $request): Response
    {
        $document = $this->design($request->validate(['document' => ['required', 'integer']])['document']);
        if ($document->text !== null) {
            throw ValidationException::withMessages(['document' => "Design {$document->id} is described already (".$document->described_at?->format('d-m-Y H:i').'); it is described again only when the file changes on Drive.']);
        }
        $pillars = array_keys($document->client->toProfileArray()['pillars'] ?? []);
        $fields = $request->validate([
            'description' => ['required', 'string', 'max:'.ClientDocument::DESCRIPTION_MAX],
            'text_on_image' => ['nullable', 'string', 'max:'.ClientDocument::DESCRIPTION_MAX],
            'topic' => ['nullable', 'string', 'max:200'],
            'pillar' => ['nullable', 'string', Rule::in($pillars)],
            'post_type' => ['nullable', 'string', Rule::in(array_keys(ClientDocument::POST_TYPES))],
            'language' => ['nullable', 'string', 'max:20'],
        ], ['pillar.in' => 'The pillar must be one of the client\'s: '.(implode(', ', $pillars) ?: 'none, leave it out').'.']);

        $text = self::text($fields);
        if (mb_strlen($text) > ClientDocument::DESCRIPTION_MAX) {
            throw ValidationException::withMessages(['description' => 'The description and its fields come to '.mb_strlen($text).' characters; at most '.ClientDocument::DESCRIPTION_MAX.' are kept. Shorten it and save again.']);
        }
        $document->forceFill(['text' => $text, 'chars' => mb_strlen($text), 'described_at' => now(), 'error' => null])->save();

        return Response::json([
            'document' => $document->id,
            'saved' => true,
            'chars' => $document->chars,
            'still_waiting' => ClaudeQueue::designs()->where('client_id', $document->client_id)->count(),
            'then' => 'Go on with the next design of the list, or stop when the batch is done.',
        ]);
    }

    /** The description as the planning brief reads it: the sentences, then one line per field given. */
    public static function text(array $fields): string
    {
        $clean = fn (?string $value) => trim(preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", (string) $value)));
        $type = ClientDocument::POST_TYPES[$fields['post_type'] ?? ''] ?? null;
        $lines = array_filter([
            $clean($fields['description'] ?? ''),
            ($on = $clean($fields['text_on_image'] ?? '')) !== '' ? "Text on it: {$on}" : null,
            ($topic = $clean($fields['topic'] ?? '')) !== '' ? "Topic: {$topic}" : null,
            $type ? "Type: {$type}" : null,
            ($pillar = $clean($fields['pillar'] ?? '')) !== '' ? "Pillar: {$pillar}" : null,
            ($language = $clean($fields['language'] ?? '')) !== '' ? "Language: {$language}" : null,
        ], fn ($line) => $line !== null && $line !== '');

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document' => $schema->integer()->description('The design\'s document id, as get-design gave it')->required(),
            'description' => $schema->string()->description('What the post shows, in a few plain sentences, from the image only')->required(),
            'text_on_image' => $schema->string()->description('The words on the design, exactly as written'),
            'topic' => $schema->string()->description('The offer, event, dish, product or theme of the post'),
            'pillar' => $schema->string()->description('One of the client\'s pillar keys (get-design lists them); leave it out when none fits'),
            'post_type' => $schema->string()->enum(array_keys(ClientDocument::POST_TYPES))->description('What kind of post it was'),
            'language' => $schema->string()->description('The language of the text on the design (el, en, ru…)'),
        ];
    }
}
