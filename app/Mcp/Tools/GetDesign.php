<?php

namespace App\Mcp\Tools;

use App\Media\GoogleDrive;
use App\Models\ClientDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Step 1 of describing a design from a client\'s Drive folder (the designs list of list-claude-jobs): Drive\'s preview of the file (a graphic, a photo, the first page of a PDF, a frame of a video), with the client, the file name, its subfolder and its Drive date. Look at the image and describe the post it was: what it shows, the exact text on it as written, the offer or event, the season. Never invent what is not visible. Write in the client\'s language as the planning brief does (Greek by default). Then save-design-description. Nothing is kept on the server but your text.')]
class GetDesign extends Tool
{
    use ScopedToToken;

    public const SIZE = 1024;

    public function handle(Request $request, GoogleDrive $drive): Response|ResponseFactory
    {
        $document = $this->design($request->validate(['document' => ['required', 'integer']])['document']);
        try {
            $preview = $drive->preview($document->source_id, self::SIZE);
        } catch (RuntimeException $e) {
            // out of the queue for a day, so one file Drive will not show never holds up the batch
            $document->forceFill(['error' => ClientDocument::PREVIEW_FAILED, 'fetched_at' => now()])->save();

            return Response::error("Drive did not hand over the preview of document {$document->id} ({$e->getMessage()}). It leaves the queue for a day; go on with the next design.");
        }

        $client = $document->client;
        $language = $client->uiLocale() === 'en' ? 'English' : 'Greek';

        return Response::make([
            Response::image($preview['bytes'], $preview['mime']),
            Response::json([
                'document' => $document->id,
                'client' => $client->slug,
                'client_name' => $client->name,
                'name' => $document->name,
                'path' => $document->path,
                'modified' => $document->modified_at?->format('d-m-Y'),
                'modified_iso' => $document->modified_at?->toDateString(),
                'already_described' => $document->text !== null,
                'write_in' => $language,
                'pillars' => array_keys($client->toProfileArray()['pillars'] ?? []),
                'post_types' => array_keys(ClientDocument::POST_TYPES),
                'instruction' => "The image is a post {$client->name} published before, as its design file in the client's Drive folder. Describe the post it was in a few plain sentences: what it shows, the exact text on it as written, the offer, event, dish or product, and the season or date it speaks of. Never invent what is not visible: no price, name, date or hour you cannot read on it. Write the description in {$language}; keep the text on the design in its own language.",
                'then' => $document->text !== null
                    ? 'This design is described already; nothing to save. Go on with the next one.'
                    : "Call save-design-description with document {$document->id}, description, text_on_image, topic, pillar (one of pillars, or none), post_type (one of post_types) and language (of the text on the design). At most ".ClientDocument::DESCRIPTION_MAX.' characters in all.',
            ]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['document' => $schema->integer()->description('The design\'s document id, from the designs list of list-claude-jobs')->required()];
    }
}
