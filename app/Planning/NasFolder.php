<?php

namespace App\Planning;

use App\Media\NasConnector;
use App\Models\Client;
use Illuminate\Support\Str;

/**
 * The client's folder on the office NAS (the profile's `nas_folder`, a path inside the NAS's Clients folder; D29). Social
 * Ops never reads it: Claude opens it itself through the Inglelandi NAS connector (read-only, github.com/inglelandi/nas-connector)
 * when it plans or writes posts for the client, and the briefs say which folder and how. The one thing copied from it is
 * the photo or video Claude puts on a post, through attach-nas-file (D30), when the connector's download key is set.
 */
final class NasFolder
{
    /** The connector as it is named in Claude's settings. */
    public const CONNECTOR = 'Inglelandi NAS';

    /** The connector's tools, as the briefs name them. */
    public const TOOLS = 'list_folder, search_files, read_document, view_image';

    /** How many NAS files copied before a brief lists, newest first. */
    public const COPIED_MAX = 200;

    /** What the note of a copied file starts with, before the NAS path. */
    public const NOTE = 'Office NAS: ';

    public static function path(Client $client): ?string
    {
        $path = trim((string) ($client->profile['nas_folder'] ?? ''));

        return $path === '' ? null : $path;
    }

    /**
     * What a brief hands Claude: the folder and how to use it, for a batch plan or for one post's captions; null when the
     * client has no folder on the NAS.
     *
     * @return array{path: string, connector: string, how: string, copies_files: bool, files_copied?: list<string>}|null
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
        $copies = NasConnector::configured();
        $brief = [
            'path' => $path,
            'connector' => self::CONNECTOR,
            'how' => $how.' Open only this folder for this client, never another client\'s. Without the connector, work from this brief and say so'.($for === 'plan' ? ' in notes_for_reviewer.' : ' when you answer.').' '.self::mediaHow($for, $copies),
            'copies_files' => $copies,
        ];
        if ($copies) {
            // the note keeps the path whole; the source id is its hash when it is too long for the column (AttachNasFile::sourceId())
            $brief['files_copied'] = $client->assets()->where('uploaded_via', 'nas')->latest('id')->limit(self::COPIED_MAX)->pluck('note')
                ->map(fn (?string $note) => Str::after((string) $note, self::NOTE))->filter()->values()->all();
        }

        return $brief;
    }

    /** The files step of a brief (D30): the posts Claude plans or writes get their photos from the folder, through attach-nas-file; or why not. */
    private static function mediaHow(string $for, bool $copies): string
    {
        if (! $copies) {
            return 'Files for the posts: do not call attach-nas-file, '.NasConnector::problem();
        }
        $which = $for === 'plan'
            ? 'Files for the posts: after save-plan, give every new post that has no files one photo or video from this folder (several for a carousel)'
            : 'Files for the post: if it has no files, give it one photo or video from this folder (several for a carousel)';

        return $which.': look at the candidates with view_image, pick what fits the post and its format (a Reel takes a video), ask the connector for get_download_link, then attach-nas-file here with the post, the url and the path, once per file. Prefer files not copied before (files_copied lists those), never a file of another client. The team changes the files in the app.';
    }
}
