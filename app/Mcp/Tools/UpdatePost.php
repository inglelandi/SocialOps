<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PostController;
use App\Http\Requests\PostRequest;
use App\Models\ClientProduct;
use App\Models\Post;
use App\Posts\PostState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change a post: only the fields you send change, the rest stays. A caption sent for one platform and language replaces that one (get-post shows the current ones). Validated like the post form; an approved post goes back to In Review for a human to approve again, a published post cannot be changed. Nothing is published here.')]
class UpdatePost extends Tool
{
    use ScopedToToken;

    public function handle(Request $request, PostController $posts): Response
    {
        $post = $this->post($request->get('post'));
        throw_if($post->state === PostState::Published, ValidationException::withMessages(['post' => 'A published post cannot be changed.']));

        $products = $request->has('products') ? $this->products($post, (array) $request->get('products')) : null;
        $given = Arr::except($request->all(), ['post', 'products']);
        $input = array_replace([
            'platforms' => $post->platforms,
            'format' => $post->format,
            'pillar' => $post->pillar,
            'brief' => $post->brief,
            'scheduled_at' => $post->scheduled_at?->format('Y-m-d H:i'),
            'assets' => $post->assets->pluck('id')->all(),
            'variants' => $post->toApiArray()['captions']->toArray(),
        ], Arr::except($given, ['variants', 'settings']), ['settings' => array_replace_recursive($post->settings ?? [], $given['settings'] ?? [])]);
        foreach ($given['variants'] ?? [] as $platform => $langs) { // per field, so a caption alone keeps the hashtags and the alt text
            foreach ((array) $langs as $lang => $fields) {
                $input['variants'][$platform][$lang] = array_replace($input['variants'][$platform][$lang] ?? [], (array) $fields);
            }
        }

        // the form's own request, so the rules and the variants are the same as in the app
        $form = PostRequest::create('/', 'POST', $input);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $route = (new Route('POST', '/', fn () => null))->bind($form);
        $route->setParameter('post', $post);
        $form->setRouteResolver(fn () => $route);
        $form->validateResolved();

        $post = $posts->updatePost($form, $post)->refresh();
        if ($products !== null) { // replaced whole, not merged: [] clears; a planning detail, so an approved post stays approved
            $settings = $post->settings ?? [];
            if ($products === []) {
                unset($settings['products']);
            } else {
                $settings['products'] = $products;
            }
            $post->forceFill(['settings' => $settings ?: null])->save();
        }

        return Response::json($post->refresh()->load('variants', 'assets')->toApiArray());
    }

    /**
     * The shop products a post links, by their find-products ids, as the post keeps them (ClientProduct::snapshot()).
     *
     * @throws ValidationException naming an id that is not in this client's shop
     */
    private function products(Post $post, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        throw_if(count($ids) > ClientProduct::PER_POST, ValidationException::withMessages(['products' => 'At most '.ClientProduct::PER_POST.' products per post.']));
        $found = $post->client->products()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            throw_unless($found->has($id), ValidationException::withMessages(['products' => "Product {$id} is not in {$post->client->name}'s shop. Use the ids find-products gives."]));
        }

        return array_map(fn ($id) => $found[$id]->snapshot(), $ids);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post' => $schema->integer()->description('Post id')->required(),
            'platforms' => $schema->array()->items($schema->string()->enum(Post::PLATFORMS))->min(1),
            'format' => $schema->string()->enum(Post::FORMATS),
            'pillar' => $schema->string()->description('One of the client\'s pillars from list-clients'),
            'brief' => $schema->string()->description('What the post is about, for the team'),
            'scheduled_at' => $schema->string()->description('When to publish, "YYYY-MM-DD HH:MM" in the client\'s local time'),
            'variants' => $schema->object()->description('Captions to change, per platform and language: {"instagram": {"el": {"caption": "..."}}} changes that caption only; "alt_text" and "hashtags" (list) likewise'),
            'settings' => $schema->object()->description('Optional: {"first_comment": "...", "instagram": {"collaborators": "@a @b"}, "repeat": "weekly"}'),
            'assets' => $schema->array()->items($schema->integer())->description('Ids of the client\'s assets, in order; replaces the list'),
            'products' => $schema->array()->items($schema->integer())->description('Ids of the client\'s shop products the post links, from find-products (at most '.ClientProduct::PER_POST.'); replaces the list, [] clears it'),
        ];
    }
}
