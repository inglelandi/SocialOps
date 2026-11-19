<?php

namespace App\Mcp\Tools;

use App\Media\NasConnector;
use App\Models\Asset;
use App\Planning\NasFolder;
use App\Posts\PostState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Throwable;

#[Description('Put a photo or video from the client\'s folder on the office NAS on a post you planned or wrote that has no files yet (D30). Look at the folder with the Inglelandi NAS connector (view_image), pick what fits the post and its format (a Reel takes a video), ask the connector for get_download_link, then call this with the post, the url it gave and the file\'s path. Social Ops copies the file into the client\'s Files once (named by the naming rule, marked Office NAS) and adds it to the post\'s media after the ones already there: one call per file, several for a carousel; a file copied before needs no url. Refused for a post with files the team chose, a post in review, approved or later (the team changes the files in the app), a file outside this client\'s NAS folder, and while Social Ops has no download key. Nothing on the NAS changes and nothing is published.')]
class AttachNasFile extends Tool
{
    use ScopedToToken;

    /** Social Ops files these as videos; the connector offers nothing else but photos. */
    private const VIDEO = ['mp4', 'mov'];

    public function handle(Request $request): Response
    {
        $input = $request->validate(['post' => ['required', 'integer'], 'path' => ['required', 'string', 'max:500'], 'url' => ['nullable', 'string', 'max:500']]);
        $post = $this->post($input['post']);
        $client = $post->client;
        $path = trim(preg_replace('#/{2,}#', '/', str_replace('\\', '/', $input['path'])), '/ ');
        $folder = NasFolder::path($client) ?? throw ValidationException::withMessages(['post' => "{$client->name} has no folder on the office NAS in its profile (nas_folder): the team names one in the profile's Research section. Leave the post without files."]);
        throw_unless($path !== $folder && str_starts_with($path.'/', $folder.'/'), ValidationException::withMessages(['path' => "\"{$path}\" is not inside {$client->name}'s NAS folder \"{$folder}\": a post takes files from its own client's folder only."]));
        throw_if($post->state === PostState::InReview, ValidationException::withMessages(['post' => "Post {$post->id} is with the client for review: the team changes its files in the app."]));
        throw_if($post->state->isLocked(), ValidationException::withMessages(['post' => "Post {$post->id} is {$post->state->label()}: the team changes its files in the app."]));
        if ($team = $post->assets->first(fn (Asset $a) => $a->uploaded_via !== 'nas')) {
            throw ValidationException::withMessages(['post' => "Post {$post->id} already has files the team chose ({$team->name}): leave them, add nothing."]);
        }
        $video = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::VIDEO, true);
        throw_if(in_array($post->format, ['reel', 'tiktok_video'], true) && ! $video, ValidationException::withMessages(['path' => "Post {$post->id} is a {$post->format}: it takes a video (MP4 or MOV), not a photo."]));
        throw_if($post->format === 'tiktok_photos' && $video, ValidationException::withMessages(['path' => "Post {$post->id} is a TikTok photo post: it takes photos, not a video."]));

        $copied = false;
        if (! $asset = $client->assets()->firstWhere('source_id', self::sourceId($path))) {
            throw_unless(NasConnector::configured(), ValidationException::withMessages(['url' => NasConnector::problem()]));
            throw_if(trim((string) ($input['url'] ?? '')) === '', ValidationException::withMessages(['url' => 'This file is not in Social Ops yet: give the url get_download_link made for it.']));
        }
        // whatever breaks from here on (the download, the disk, the database) is said in the answer: the MCP layer would
        // otherwise hide it behind "An internal server error occurred", which left the worker guessing (2026-11-19)
        try {
            if (! $asset) {
                try {
                    $upload = NasConnector::fetch($input['url'], basename($path));
                } catch (RuntimeException $e) {
                    throw ValidationException::withMessages(['url' => $e->getMessage()]);
                }
                try {
                    // filed like a folder pull: once per NAS path, named by the naming rule with the post's pillar; the note keeps the path (NasFolder::brief() lists them)
                    $asset = $client->storeAsset($upload, ['note' => Str::limit(NasFolder::NOTE.$path, 500, ''), 'uploaded_via' => 'nas', 'source_id' => self::sourceId($path), 'pillar' => $post->pillar]);
                } finally {
                    @unlink($upload->getPathname());
                }
                $copied = $asset->wasRecentlyCreated;
            }
            if (! $post->assets->contains($asset)) {
                $post->assets()->attach($asset->id, ['position' => (int) ($post->assets->max('pivot.position') ?? -1) + 1]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => self::failure($e)]);
        }
        $post->unsetRelation('assets')->load('assets');

        return Response::json([
            'post' => $post->id,
            'asset' => ['id' => $asset->id, 'name' => $asset->name, 'kind' => $asset->kind, 'size' => $asset->size, 'copied' => $copied, 'on_posts' => $asset->posts()->count()],
            'media' => $post->assets->map(fn (Asset $a) => ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind])->values(),
            'then' => $copied ? 'The file is on the post. Add another for a carousel, or go on with the next post.' : 'The file was in Social Ops already (copied from the NAS before); it is on the post now.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->description('The post id (save-plan\'s answer, list-claude-jobs or list-posts)')->required(),
            'path' => $schema->string()->description('The file\'s path inside Clients on the NAS, as the connector lists it, e.g. "Dokos Home/Photos/IMG_0001.jpg"')->required(),
            'url' => $schema->string()->description('The url get_download_link gave for this file; not needed for a file copied before'),
        ];
    }

    /** The asset's source, one per NAS path: the path itself while it fits the column (100 characters), else its hash. */
    public static function sourceId(string $path): string
    {
        return strlen("nas:{$path}") <= 100 ? "nas:{$path}" : 'nas:'.sha1($path);
    }

    /** What went wrong after the checks, in words for Claude and the team: the exception, and what a file that could not be written means on the server. */
    public static function failure(Throwable $e): string
    {
        $message = $e->getMessage();
        $disk = str_starts_with($message, 'Could not write') || str_contains($message, 'ermission denied') || str_contains(class_basename($e), 'UnableTo');
        $hint = $disk
            ? ' The client\'s files folder on the server cannot be written by the site\'s user: an admin opens Refresh after update (it counts such folders) and has them given to the site\'s user in Plesk (plesk repair fs).'
            : ' The server log has the details.';

        return 'Social Ops could not file the download ('.class_basename($e).': '.Str::limit($message, 300).').'.$hint.' Leave the post without files and tell the team.';
    }
}
