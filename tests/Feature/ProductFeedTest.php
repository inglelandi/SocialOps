<?php

use App\Jobs\SyncProductFeed;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\FindProducts;
use App\Mcp\Tools\GetCaptionBrief;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\SavePlan;
use App\Mcp\Tools\UpdatePost;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientProduct;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Posts\PostState;
use App\Products\ProductFeed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Products in the posts Claude plans (Panos, 2026-11-10: "the most correct way to show products in posts on the plan with
 * claude. An xml with products"): the shop's product feed, linked on the Files tab, read daily into client_products, searched
 * by find-products, and linked on the Drafts (settings.products) through save-plan and update-post.
 */

const SKROUTZ_FEED = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<mywebstore><created_at>2026-11-10 06:00</created_at><products>
<product><id>1531</id><name><![CDATA[Σεντόνι Inventor 160x200 λευκό]]></name><link><![CDATA[https://shop.example.gr/lefka-eidi/1531-sentoni.html]]></link><image>https://shop.example.gr/img/1531.jpg</image><category><![CDATA[Λευκά είδη > Σεντόνια]]></category><price_with_vat>39,90</price_with_vat><availability>Άμεσα διαθέσιμο</availability><manufacturer>Dokos</manufacturer><instock>Y</instock><description><![CDATA[<p>Βαμβακερό σεντόνι &amp; μαξιλαροθήκες</p>]]></description></product>
<product><id>1532</id><name>Πάπλωμα Winter μονό</name><link>https://shop.example.gr/1532-paploma.html</link><category>Λευκά είδη > Παπλώματα</category><price_with_vat>59,00</price_with_vat><instock>N</instock></product>
<product><id>1533</id><name>Στρώμα Acanthus CANDIA</name><link>https://shop.example.gr/1533-stroma.html</link><category>Στρώματα</category><price_with_vat>450,00</price_with_vat><availability>Παράδοση σε 4-10 ημέρες</availability></product>
<product><id>1534</id><name>A product without a link</name></product>
<product><id>1531</id><name>The same id again</name><link>https://shop.example.gr/dup</link></product>
</products></mywebstore>
XML;

const GOOGLE_FEED = <<<'XML'
<?xml version="1.0"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0"><channel><title>Shop</title><link>https://shop.example.com</link>
<item><g:id>A1</g:id><g:title>Linen throw</g:title><g:link>https://shop.example.com/p/a1</g:link><g:price>29.90 EUR</g:price><g:sale_price>19.90 EUR</g:sale_price><g:product_type>Home &gt; Throws</g:product_type><g:brand>Acme</g:brand><g:image_link>https://shop.example.com/a1.jpg</g:image_link><g:availability>in stock</g:availability></item>
<item><g:id>A2</g:id><title>Cotton towel</title><link>https://shop.example.com/p/a2</link><g:price>9.90 EUR</g:price><g:availability>out of stock</g:availability></item>
</channel></rss>
XML;

const ATOM_FEED = <<<'XML'
<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0"><title>Shop</title><link href="https://shop.example.com"/>
<entry><g:id>B1</g:id><title>Bath robe</title><link href="https://shop.example.com/p/b1"/><g:price>49 EUR</g:price></entry>
</feed>
XML;

beforeEach(function () {
    ProductFeed::$resolver = fn () => ['93.184.216.34']; // a public address, without DNS
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Dokos Home', 'slug' => 'dokos']);
});

afterEach(fn () => ProductFeed::$resolver = null);

/** The feed as a file, read the way the job reads it. */
function parsedFeed(string $xml): array
{
    $path = tempnam(sys_get_temp_dir(), 'feed');
    file_put_contents($path, $xml);
    try {
        return iterator_to_array(ProductFeed::parse($path), false);
    } finally {
        unlink($path);
    }
}

/**
 * The client's feed linked and read from a faked shop. Called again in a test, it changes what that shop answers (the first
 * stub stays registered, so it reads the answer live); a [body, status] pair answers with that status.
 */
function readFeed(Client $client, string|array $xml, string $url = 'https://shop.example.gr/feeds/skroutz.xml'): Account
{
    $host = parse_url($url, PHP_URL_HOST);
    $GLOBALS['shopFeeds'][$host] = (array) $xml + [1 => 200];
    Http::fake([$host.'/*' => fn () => Http::response(...$GLOBALS['shopFeeds'][$host])]);
    $feed = $client->accounts()->updateOrCreate(['platform' => 'product_feed'], ['external_id' => sha1($url), 'name' => parse_url($url, PHP_URL_HOST), 'access_token' => '', 'meta' => ['url' => $url]]);
    (new SyncProductFeed($feed))->handle();

    return $feed->fresh();
}

test('a Skroutz, a Google RSS and an Atom feed give the same fields, the stock read from flags, counts or words', function () {
    $skroutz = parsedFeed(SKROUTZ_FEED);
    expect($skroutz)->toHaveCount(4) // the one without a link is skipped; the repeated id is the job's to drop
        ->and($skroutz[0])->toMatchArray(['record' => 'product', 'external_id' => '1531', 'name' => 'Σεντόνι Inventor 160x200 λευκό', 'url' => 'https://shop.example.gr/lefka-eidi/1531-sentoni.html',
            'price' => '39,90', 'category' => 'Λευκά είδη > Σεντόνια', 'brand' => 'Dokos', 'image' => 'https://shop.example.gr/img/1531.jpg', 'in_stock' => true, 'description' => 'Βαμβακερό σεντόνι & μαξιλαροθήκες'])
        ->and($skroutz[1]['in_stock'])->toBeFalse()
        ->and($skroutz[2]['in_stock'])->toBeTrue(); // "Παράδοση σε 4-10 ημέρες"

    $google = parsedFeed(GOOGLE_FEED);
    expect($google)->toHaveCount(2)
        ->and($google[0])->toMatchArray(['record' => 'item', 'external_id' => 'A1', 'name' => 'Linen throw', 'url' => 'https://shop.example.com/p/a1', 'price' => '29.90 EUR', 'sale_price' => '19.90 EUR', 'category' => 'Home > Throws', 'brand' => 'Acme', 'in_stock' => true])
        ->and($google[1])->toMatchArray(['name' => 'Cotton towel', 'url' => 'https://shop.example.com/p/a2', 'in_stock' => false]);

    expect(parsedFeed(ATOM_FEED))->toBe([['record' => 'entry', 'external_id' => 'B1', 'name' => 'Bath robe', 'url' => 'https://shop.example.com/p/b1', 'price' => '49 EUR', 'sale_price' => null, 'category' => null, 'brand' => null, 'image' => null, 'in_stock' => null, 'description' => null]]);

    expect(ProductFeed::stock(['availability' => 'Μη διαθέσιμο']))->toBeFalse()->and(ProductFeed::stock(['availability' => 'Κατόπιν παραγγελίας']))->toBeFalse()
        ->and(ProductFeed::stock(['quantity' => '3']))->toBeTrue()->and(ProductFeed::stock(['stock' => '0']))->toBeFalse()->and(ProductFeed::stock([]))->toBeNull();
    expect(fn () => parsedFeed('this is not XML at all'))->toThrow(RuntimeException::class);
});

test('only a public http(s) address is fetched: never the server itself or its network', function () {
    expect(fn () => ProductFeed::guard('ftp://shop.example.gr/feed.xml'))->toThrow(RuntimeException::class, 'Paste the full link')
        ->and(fn () => ProductFeed::guard('shop.example.gr/feed.xml'))->toThrow(RuntimeException::class, 'Paste the full link')
        ->and(fn () => ProductFeed::guard('http://127.0.0.1/feed.xml'))->toThrow(RuntimeException::class, 'not a public shop')
        ->and(fn () => ProductFeed::guard('http://192.168.1.10/feed.xml'))->toThrow(RuntimeException::class, 'not a public shop')
        ->and(fn () => ProductFeed::guard('http://[::1]/feed.xml'))->toThrow(RuntimeException::class, 'not a public shop');
    ProductFeed::$resolver = fn () => ['10.0.0.5'];
    expect(fn () => ProductFeed::guard('https://intranet.example.gr/feed.xml'))->toThrow(RuntimeException::class, 'not a public shop');
    ProductFeed::$resolver = fn () => false;
    expect(fn () => ProductFeed::guard('https://nowhere.example.gr/feed.xml'))->toThrow(RuntimeException::class, 'could not be found');
    ProductFeed::$resolver = fn () => ['93.184.216.34'];
    ProductFeed::guard('https://shop.example.gr/feed.xml');
    expect(true)->toBeTrue();
});

test('the sync files the products once, updates them on the next read, drops the gone ones, and keeps them when a read fails', function () {
    $feed = readFeed($this->client, SKROUTZ_FEED);
    expect($this->client->products()->count())->toBe(3)
        ->and($feed->meta)->toMatchArray(['count' => 3, 'in_stock' => 2, 'format' => 'Skroutz / BestPrice XML', 'capped' => false, 'error' => null])
        ->and(ClientProduct::where('external_id', '1531')->sole())->toMatchArray(['name' => 'Σεντόνι Inventor 160x200 λευκό', 'account_id' => $feed->id, 'search' => 'σεντονι inventor 160x200 λευκο λευκα ειδη > σεντονια dokos βαμβακερο σεντονι & μαξιλαροθηκεσ']);

    // the next day: 1532 is gone from the feed and 1531's price changed
    $this->travel(1)->days();
    $next = str_replace(['<price_with_vat>39,90</price_with_vat>', '<product><id>1532</id>'], ['<price_with_vat>34,90</price_with_vat>', '<product><id>9999</id>'], SKROUTZ_FEED);
    readFeed($this->client, $next);
    expect($this->client->products()->pluck('external_id')->sort()->values()->all())->toBe(['1531', '1533', '9999'])
        ->and(ClientProduct::where('external_id', '1531')->value('price'))->toBe('34,90');

    // a page instead of a feed, and a shop that does not answer: the products stay, the tab says why
    readFeed($this->client, '<html><body>Our shop</body></html>');
    expect($this->client->products()->count())->toBe(3)->and($feed->fresh()->meta['error'])->toStartWith('No products in this file.');
    readFeed($this->client, ['gone', 404]);
    expect($this->client->products()->count())->toBe(3)->and($feed->fresh()->meta['error'])->toBe("The shop answered 404 for the feed's link.");
});

test('the Files tab links a feed, refuses a private address, reads it, searches it like Claude, and unlinks it with its products', function () {
    Queue::fake();
    $tab = route('clients.files', $this->client);
    $this->actingAs($this->user)->get($tab)->assertOk()->assertSee('Product feed')->assertSee('name="feed"', false);

    $this->actingAs($this->user)->post(route('clients.files.feed', $this->client), ['feed' => 'http://127.0.0.1/feed.xml'])->assertRedirect($tab)->assertSessionHasErrors(['feed' => 'The address 127.0.0.1 is not a public shop.']);
    $this->actingAs($this->user)->post(route('clients.files.feed', $this->client), ['feed' => 'https://shop.example.gr/feeds/skroutz.xml'])
        ->assertRedirect($tab)->assertSessionHas('status', 'Linked the product feed of shop.example.gr, its products are being read');
    $feed = $this->client->accounts()->where('platform', 'product_feed')->sole();
    expect($feed)->toMatchArray(['name' => 'shop.example.gr', 'connected_by' => $this->user->id, 'token_expires_at' => null])->and($feed->meta)->toBe(['url' => 'https://shop.example.gr/feeds/skroutz.xml']);
    Queue::assertPushed(SyncProductFeed::class, fn (SyncProductFeed $job) => $job->account->is($feed));
    $this->actingAs($this->user)->get($tab)->assertOk()->assertSee('Not read yet')->assertSee('Read the feed now')->assertDontSee('name="feed"', false);

    readFeed($this->client, SKROUTZ_FEED);
    $html = $this->actingAs($this->user)->get($tab)->assertOk()->getContent();
    expect($html)->toContain('3 products (2 in stock)')->toContain('Σεντόνι Inventor 160x200 λευκό')->toContain('Out of stock')->toContain('Search the products, as Claude does');
    $this->actingAs($this->user)->get(route('clients.files', [$this->client, 'product' => 'ΣΤΡΩΜΑ']))->assertOk()->assertSee('Στρώμα Acanthus CANDIA')->assertDontSee('Πάπλωμα Winter μονό');
    $this->actingAs($this->user)->get(route('clients.files', [$this->client, 'product' => 'zzz']))->assertOk()->assertSee('No product matches these words.');

    $this->actingAs($this->user)->post(route('clients.files.feed.sync', $this->client))->assertRedirect($tab)->assertSessionHas('status', 'Reading the product feed now, the list refreshes in a minute or two');
    $this->actingAs($this->user)->get(route('clients.accounts', $this->client))->assertOk()->assertDontSee('shop.example.gr');

    $limited = User::factory()->create(['client_ids' => [Client::factory()->create()->id]]);
    $this->actingAs($limited)->post(route('clients.files.feed', $this->client), ['feed' => 'https://shop.example.gr/x.xml'])->assertForbidden();
    $this->actingAs($limited)->delete(route('accounts.destroy', $feed))->assertForbidden();

    $this->actingAs($this->user)->delete(route('accounts.destroy', $feed))->assertRedirect($tab)->assertSessionHas('status', 'Product feed unlinked, its products are forgotten here');
    expect(Account::where('platform', 'product_feed')->exists())->toBeFalse()->and(ClientProduct::count())->toBe(0);
});

test('find-products searches the client\'s shop without accents or case, in stock first, and never another client\'s', function () {
    SocialOpsServer::tool(FindProducts::class, ['client' => 'dokos', 'query' => 'σεντόνι'])->assertHasErrors(['Dokos Home has no product feed linked']);

    readFeed($this->client, SKROUTZ_FEED);
    $other = Client::factory()->create(['slug' => 'other']);
    readFeed($other, GOOGLE_FEED, 'https://shop.example.com/feed.xml');

    SocialOpsServer::tool(FindProducts::class, ['client' => 'dokos', 'query' => 'ΣΕΝΤΟΝΙΑ λευκα'])->assertOk()
        ->assertSee(['"name":"Σεντόνι Inventor 160x200 λευκό"', '"url":"https://shop.example.gr/lefka-eidi/1531-sentoni.html"', '"price":"39,90"', '"in_stock":true', '"shop":{"products":3,"in_stock":2}', 'Facebook: put the product'])
        ->assertDontSee(['Πάπλωμα Winter', 'Linen throw']); // out of stock by default; another client's shop never
    SocialOpsServer::tool(FindProducts::class, ['client' => 'dokos', 'query' => 'παπλωμα', 'in_stock_only' => false])->assertOk()->assertSee('Πάπλωμα Winter μονό');
    SocialOpsServer::tool(FindProducts::class, ['client' => 'dokos', 'query' => 'linen'])->assertOk()->assertSee(['"products":[]', 'Nothing matches']);

    $names = ClientProduct::search($this->client, 'acanthus στρωμα σεντονι', 2)->pluck('name')->all();
    expect($names)->toBe(['Στρώμα Acanthus CANDIA', 'Σεντόνι Inventor 160x200 λευκό']); // two words in the name beat one
});

test('save-plan keeps the products a post links on its Draft and its review sheet, and refuses an id from outside the shop', function () {
    readFeed($this->client, SKROUTZ_FEED);
    $sheet = ClientProduct::where('external_id', '1531')->sole();
    $other = readFeed(Client::factory()->create(), GOOGLE_FEED, 'https://shop.example.com/feed.xml')->products()->first();
    $post = fn (array $extra) => $extra + ['date' => '2027-05-12', 'platforms' => ['facebook'], 'format' => 'feed', 'title' => 'Winter sheets', 'angle' => 'Cotton sheets for the cold nights.', 'captions' => ['el' => ['facebook' => 'Ζεστά σεντόνια: '.$sheet->url]]];

    SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [$post(['products' => [$other->id]])]])->assertHasErrors(["\"Winter sheets\": product {$other->id} is not in Dokos Home's shop."]);
    expect(Post::count())->toBe(0);

    SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [$post(['products' => [$sheet->id, $sheet->id]])]])->assertOk();
    expect(Post::sole()->settings['products'])->toBe([['id' => $sheet->id, 'name' => 'Σεντόνι Inventor 160x200 λευκό', 'url' => 'https://shop.example.gr/lefka-eidi/1531-sentoni.html', 'price' => '39,90']])
        ->and(Report::where('kind', 'plan')->sole()->body)->toContain('Προϊόν: Σεντόνι Inventor 160x200 λευκό (https://shop.example.gr/lefka-eidi/1531-sentoni.html)'); // the sheet is in the client's language
});

test('update-post sets, replaces and clears a post\'s products without sending an approved post back to review', function () {
    readFeed($this->client, SKROUTZ_FEED);
    [$sheet, $mattress] = [ClientProduct::where('external_id', '1531')->sole(), ClientProduct::where('external_id', '1533')->sole()];
    $post = Post::factory()->create(['client_id' => $this->client->id, 'state' => PostState::Draft, 'platforms' => ['facebook'], 'format' => 'feed']);
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'variants' => ['facebook' => ['el' => ['caption' => 'Σεντόνια'], 'en' => ['caption' => 'Sheets']]]])->assertOk(); // its captions first, as an approved post has them
    $post->forceFill(['state' => PostState::Approved])->save();

    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'products' => [$sheet->id, $mattress->id]])->assertOk()->assertSee('"products":[{"id":'.$sheet->id);
    expect($post->fresh()->settings['products'])->toHaveCount(2)->and($post->fresh()->state)->toBe(PostState::Approved);
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'products' => [$mattress->id]])->assertOk();
    expect(array_column($post->fresh()->settings['products'], 'id'))->toBe([$mattress->id]);
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'products' => [123456]])->assertHasErrors(["Product 123456 is not in Dokos Home's shop."]);
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'products' => []])->assertOk();
    expect($post->fresh()->settings['products'] ?? null)->toBeNull()->and($post->fresh()->state)->toBe(PostState::Approved);
});

test('the planning and caption briefs describe the shop and point to find-products; without a feed they say nothing of it', function () {
    $post = Post::factory()->create(['client_id' => $this->client->id, 'platforms' => ['facebook'], 'format' => 'feed']);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 2])->assertOk()->assertSee(['"shop":null', "## The client's shop (its product feed)", 'no product links.\nnone']);
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $post->id])->assertOk()->assertSee('"shop":null')->assertDontSee('find-products');

    readFeed($this->client, SKROUTZ_FEED);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 2])->assertOk()
        ->assertSee(["no product links.\\nThe client's shop has 3 products (2 marked in stock, feed read", 'Biggest categories: Λευκά είδη > Παπλώματα (1); Λευκά είδη > Σεντόνια (1); Στρώματα (1).', 'Search it with find-products.', 'products (the ids find-products gave']);
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $post->id])->assertOk()->assertSee(["The client's shop has 3 products", 'find real ones with find-products', '"products":[]']);
});

test('the post page and Post today show the linked products, with a copy button for each link', function () {
    $this->travelTo(now()->setTime(10, 0));
    $products = [['id' => 7, 'name' => 'Σεντόνι Inventor', 'url' => 'https://shop.example.gr/1531.html', 'price' => '39,90']];
    $post = Post::factory()->create(['client_id' => $this->client->id, 'state' => PostState::Approved, 'platforms' => ['facebook'], 'format' => 'feed', 'scheduled_at' => today()->setTime(18, 0), 'settings' => ['products' => $products]]);

    $this->actingAs($this->user)->get(route('posts.edit', $post))->assertOk()->assertSee('Products in this post')->assertSee('Σεντόνι Inventor')->assertSee('https://shop.example.gr/1531.html');
    $this->actingAs($this->user)->get(route('today'))->assertOk()->assertSee('Product links')->assertSee('data-copy="https://shop.example.gr/1531.html"', false);
});

test('products:pull queues a read of every product feed, and the client page counts the products', function () {
    Queue::fake();
    $feed = $this->client->accounts()->create(['platform' => 'product_feed', 'external_id' => 'x', 'name' => 'shop.example.gr', 'access_token' => '', 'meta' => ['url' => 'https://shop.example.gr/feed.xml']]);
    $this->artisan('products:pull')->expectsOutputToContain('shop.example.gr: product feed queued')->assertSuccessful();
    Queue::assertPushed(SyncProductFeed::class, fn (SyncProductFeed $job) => $job->account->is($feed));

    $feed->products()->make(['external_id' => '1', 'name' => 'Σεντόνι', 'url' => 'https://shop.example.gr/1', 'search' => 'σεντονι'])->forceFill(['client_id' => $this->client->id])->save();
    config()->set('features.claude_api', false);
    $this->actingAs($this->user)->get(route('clients.planner', $this->client))->assertOk()->assertSee('And 1 products from the shop.');
});
