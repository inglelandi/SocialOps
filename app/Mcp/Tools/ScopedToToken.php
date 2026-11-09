<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** What the caller may see: the token's client over HTTP (ApiAuth), the login's clients behind an OAuth token (McpAuth), every client over stdio. */
trait ScopedToToken
{
    protected function clients(): Builder
    {
        $token = request()->attributes->get('api_token');
        $user = request()->user();

        return Client::query()->when($token?->client_id, fn ($q, $id) => $q->whereKey($id))->when($user, fn ($q) => $user->restrict($q, 'id'))->orderBy('name');
    }

    /** A client by id or slug, or a validation error the caller can read. */
    protected function client(mixed $idOrSlug): Client
    {
        // an id only when it is all digits: (int) "2-lounge" is 2, which picked client 2 for the slug "2-lounge"
        $byId = ctype_digit((string) $idOrSlug) ? $this->clients()->whereKey((int) $idOrSlug)->first() : null;

        return $byId ?? $this->clients()->where('slug', (string) $idOrSlug)->first()
            ?? throw ValidationException::withMessages(['client' => "No client \"$idOrSlug\" within this token's reach; see list-clients."]);
    }

    protected function post(mixed $id): Post
    {
        return Post::with('variants', 'assets')->whereIn('client_id', $this->clients()->select('id'))->find((int) $id)
            ?? throw ValidationException::withMessages(['post' => "No post $id within this token's reach."]);
    }

    /** A design file of a client's Drive folder (kind design, with a preview), within reach. */
    protected function design(mixed $id): ClientDocument
    {
        return ClientDocument::with('client')->whereIn('client_id', $this->clients()->select('id'))->where('kind', 'design')->whereNull('error')->find((int) $id)
            ?? throw ValidationException::withMessages(['document' => "No design $id within this token's reach (see the designs of list-claude-jobs)."]);
    }

    protected function comment(mixed $id): Comment
    {
        return Comment::whereIn('client_id', $this->clients()->select('id'))->find((int) $id)
            ?? throw ValidationException::withMessages(['comment' => "No comment $id within this token's reach."]);
    }
}
