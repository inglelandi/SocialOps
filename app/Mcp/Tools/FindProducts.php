<?php

namespace App\Mcp\Tools;

use App\Models\ClientProduct;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Search a client\'s shop for real products to link in a post: name, link, price, sale price, category, brand, in stock. The products come from the client\'s product feed (the XML the shop makes for Skroutz, BestPrice, Google Shopping or Facebook, linked on the Files tab and read daily). Give words from the post\'s topic, as the shop writes them; at most 20 products, in stock unless in_stock_only is false. Put a product\'s link in the caption as it is and its id in the post\'s products (save-plan or update-post). Never invent a product, a link or a price.')]
class FindProducts extends Tool
{
    use ScopedToToken;

    public function handle(Request $request): Response
    {
        $client = $this->client($request->get('client'));
        $input = $request->validate([
            'query' => ['required', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ClientProduct::SEARCH_MAX],
            'in_stock_only' => ['nullable', 'boolean'],
        ]);
        if (! $client->accounts()->where('platform', 'product_feed')->exists()) {
            return Response::error("{$client->name} has no product feed linked (the Files tab in the app), so there are no products to link. Write the post without product links.");
        }

        $products = ClientProduct::search($client, $input['query'], (int) ($input['limit'] ?? 8), (bool) ($input['in_stock_only'] ?? true));

        return Response::json([
            'client' => $client->slug,
            'query' => $input['query'],
            'products' => $products->map(fn (ClientProduct $p) => $p->toToolArray())->all(),
            'shop' => ['products' => $client->products()->count(), 'in_stock' => $client->products()->where('in_stock', true)->count()],
            'then' => $products->isEmpty()
                ? 'Nothing matches. Try the shop\'s own words (Greek or English, one product or category at a time), or write the post without product links. Never invent a product.'
                : 'Link only products that fit the post. Facebook: put the product\'s url in the caption exactly as it is. Instagram: no link in the caption (Instagram does not make it clickable): say "link in bio" or put the url in the first comment. Send the ids as the post\'s products (save-plan posts[].products, or update-post products). Name a price only when the team\'s instruction or the client\'s facts ask for it.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client' => $schema->string()->description('Client id or slug')->required(),
            'query' => $schema->string()->description('Words from the post\'s topic: a product, a category or a brand, as the shop writes them (Greek or English)')->required(),
            'limit' => $schema->integer()->description('How many products, 1 to '.ClientProduct::SEARCH_MAX.' (default 8)'),
            'in_stock_only' => $schema->boolean()->description('Only products in stock, or whose stock the feed does not give (default true)'),
        ];
    }
}
