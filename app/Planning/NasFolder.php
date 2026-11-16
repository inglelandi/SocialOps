<?php

namespace App\Planning;

use App\Models\Client;

/**
 * The client's folder on the office NAS (the profile's `nas_folder`, a path inside the NAS's Clients folder; D29). Social
 * Ops never reads it and keeps nothing from it: Claude opens it itself through the Inglelandi NAS connector (read-only,
 * github.com/inglelandi/nas-connector) when it plans or writes posts for the client, and the briefs say which folder and how.
 */
final class NasFolder
{
    /** The connector as it is named in Claude's settings. */
    public const CONNECTOR = 'Inglelandi NAS';

    /** The connector's tools, as the briefs name them. */
    public const TOOLS = 'list_folder, search_files, read_document, view_image';

    public static function path(Client $client): ?string
    {
        $path = trim((string) ($client->profile['nas_folder'] ?? ''));

        return $path === '' ? null : $path;
    }

    /**
     * What a brief hands Claude: the folder and how to use it, for a batch plan or for one post's captions; null when the
     * client has no folder on the NAS.
     *
     * @return array{path: string, connector: string, how: string}|null
     */
    public static function brief(Client $client, string $for = 'plan'): ?array
    {
        if (($path = self::path($client)) === null) {
            return null;
        }
        $open = 'open this client\'s folder on the office NAS, "'.$path.'" inside Clients, with the '.self::CONNECTOR.' connector ('.self::TOOLS.')';
        $how = $for === 'plan'
            ? 'Before you plan, '.$open.': the newest posts and captions (documents, slides, spreadsheets, content calendars), the latest designs (view_image on the newest images), the brand guides and notes. Use it like the client\'s Drive files above: what was posted is never planned again, facts and offers come from there and nothing is invented.'
            : 'Before you write, '.$open.': the facts, the captions the client posted before on the same kind of post, and this post\'s design if it is there (search_files with words from the title).';

        return [
            'path' => $path,
            'connector' => self::CONNECTOR,
            'how' => $how.' Open only this folder for this client, never another client\'s. Without the connector, work from this brief and say so'.($for === 'plan' ? ' in notes_for_reviewer.' : ' when you answer.'),
        ];
    }
}
