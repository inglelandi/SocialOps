<?php

use App\Drafting\CaptionDrafter;
use App\Jobs\SyncClientDocuments;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetPlanningBrief;
use App\Media\GoogleDrive;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\User;
use App\Planning\ClientReference;
use App\Planning\ContentPlanner;
use App\Planning\PlanRequest;
use App\Planning\TrendResearcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * The team's posts slideshow of a client (Panos, 2026-11-09: "a field that i will link a drive slideshow file where it has
 * the posts that will be uploaded… take it in consideration when i ask you to generate posts/plans"): one Google Slides
 * file on the Files tab, read as text through the service account, in the planning brief next to the reference folder.
 */

const SLIDES_ID = '15JREh0m02WVAb2JW0vJBVJ5p264Lcgtph3N_RIrH9yw';

const SLIDES_TEXT = "7/11 SCHEDULED\nΜικροί κόσμοι, γεμάτοι χρώμα και φαντασία!\nwww.dokoshome.gr\n\n09/11\nBLACK FRIDAY\nΟι Black Friday προσφορές που δεν πρέπει να χάσεις!\n\n3. Πάπλωμα ή κουβερτοπάπλωμα;\nEducational carousel";

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Dokos Home', 'code' => 'DOKOS', 'slug' => 'dokos']);
});

/** A service account key file like the one Google hands out, with a throwaway key, and the config pointing at it. */
function slideshowKey(): void
{
    static $path;
    if (! $path) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        file_put_contents($path = sys_get_temp_dir().'/socialops-test-slideshow-key.json', json_encode(['client_email' => 'ops@social-ops.iam.gserviceaccount.com', 'private_key' => $pem]));
    }
    config(['services.google.service_account' => $path]);
}

/** The client's linked slideshow with its text, as a read would leave it. */
function linkedSlideshow(Client $client, string $text = SLIDES_TEXT, array $meta = []): Account
{
    $account = $client->accounts()->create(['platform' => 'drive_slides', 'external_id' => SLIDES_ID, 'name' => 'Dokos Home', 'access_token' => '', 'meta' => ['file' => SLIDES_ID, 'mime' => 'application/vnd.google-apps.presentation', 'synced_at' => '2026-11-09 13:10:00'] + $meta]);
    $document = $account->documents()->make(['source_id' => SLIDES_ID, 'name' => 'Dokos Home', 'path' => '', 'mime' => 'application/vnd.google-apps.presentation', 'kind' => 'upcoming_posts', 'modified_at' => '2026-11-09 15:08:16', 'text' => $text, 'chars' => mb_strlen($text)])->forceFill(['client_id' => $client->id]);
    $document->save();

    return $account;
}

/**
 * Drive as the service account sees the slideshow: its metadata, its text export, a folder, a photo and a missing file.
 * Called again in a test, it changes what the slideshow answers (the first stubs stay registered, so they read it live).
 */
function fakeSlidesDrive(string $modified = '2026-11-09T13:08:16.570Z', string $text = SLIDES_TEXT): void
{
    $GLOBALS['slidesDrive'] = ['modified' => $modified, 'text' => $text];
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/'.SLIDES_ID.'/export*' => fn () => Http::response($GLOBALS['slidesDrive']['text']),
        'www.googleapis.com/drive/v3/files/'.SLIDES_ID.'*' => fn () => Http::response(['id' => SLIDES_ID, 'name' => 'Dokos Home', 'mimeType' => 'application/vnd.google-apps.presentation', 'modifiedTime' => $GLOBALS['slidesDrive']['modified']]),
        'www.googleapis.com/drive/v3/files/otherdeck123456*' => Http::response(['id' => 'otherdeck123456', 'name' => 'Dokos plan.pptx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'modifiedTime' => $modified, 'size' => '1234']),
        'www.googleapis.com/drive/v3/files/folder12345678*' => Http::response(['id' => 'folder12345678', 'name' => 'Dokos Home', 'mimeType' => 'application/vnd.google-apps.folder']),
        'www.googleapis.com/drive/v3/files/photo123456789*' => Http::response(['id' => 'photo123456789', 'name' => 'BF.jpg', 'mimeType' => 'image/jpeg']),
        'www.googleapis.com/drive/v3/files/missing1234567*' => Http::response(['error' => ['message' => 'File not found']], 404),
    ]);
}

test('a pasted Slides, Docs, Sheets or Drive link gives the file id', function () {
    expect(GoogleDrive::fileId('https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit?slide=id.hc7ab3add2b0a320_0_0'))->toBe(SLIDES_ID)
        ->and(GoogleDrive::fileId('https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit#slide=id.p'))->toBe(SLIDES_ID)
        ->and(GoogleDrive::fileId('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOp/edit?gid=0'))->toBe('1AbCdEfGhIjKlMnOp')
        ->and(GoogleDrive::fileId('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view?usp=sharing'))->toBe('1AbCdEfGhIjKlMnOp')
        ->and(GoogleDrive::fileId('https://drive.google.com/open?id=1AbCdEfGhIjKlMnOp'))->toBe('1AbCdEfGhIjKlMnOp')
        ->and(GoogleDrive::fileId(' '.SLIDES_ID.' '))->toBe(SLIDES_ID);
});

test('the Files tab links the slideshow after checking it as the service account, reads it at once, shows what Claude reads, and unlinks it', function () {
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->assertSee('Posts slideshow')->assertDontSee('name="slideshow"', false);
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => SLIDES_ID])->assertNotFound();

    slideshowKey();
    fakeSlidesDrive();
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Share the file with ops@social-ops.iam.gserviceaccount.com (Viewer is enough), or open it to anyone with the link, before linking it.')->assertSee('name="slideshow"', false);

    $tab = route('clients.files', $this->client);
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => 'https://docs.google.com/presentation/d/missing1234567/edit'])
        ->assertRedirect($tab)->assertSessionHasErrors(['slideshow' => 'Google Drive: file not found. Share it with ops@social-ops.iam.gserviceaccount.com (Viewer is enough), or open it to anyone with the link, first.']);
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => 'https://drive.google.com/drive/folders/folder12345678'])
        ->assertSessionHasErrors(['slideshow' => 'Google Drive: that link is a folder; link the slideshow file itself.']);
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => 'https://drive.google.com/file/d/photo123456789/view'])
        ->assertSessionHasErrors(['slideshow' => 'Google Drive: “BF.jpg” is not a Google Slides, Docs or Sheets file, nor a PowerPoint, Word or Excel file.']);
    expect(Account::where('platform', 'drive_slides')->exists())->toBeFalse();

    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => 'https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit?slide=id.hc7ab3add2b0a320_0_0'])
        ->assertRedirect($tab)->assertSessionHas('status', 'Linked “Dokos Home”, Claude reads it when it plans this client');
    $account = $this->client->accounts()->where('platform', 'drive_slides')->sole();
    expect($account)->toMatchArray(['external_id' => SLIDES_ID, 'name' => 'Dokos Home', 'connected_by' => $this->user->id, 'token_expires_at' => null])
        ->and($account->meta)->toMatchArray(['file' => SLIDES_ID, 'mime' => 'application/vnd.google-apps.presentation', 'error' => null]);
    $document = $account->documents()->sole();
    expect($document)->toMatchArray(['client_id' => $this->client->id, 'kind' => 'upcoming_posts', 'text' => SLIDES_TEXT, 'chars' => mb_strlen(SLIDES_TEXT), 'error' => null]);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/'.SLIDES_ID.'/export') && str_contains($request->url(), 'mimeType=text%2Fplain') && $request->hasHeader('Authorization', 'Bearer sa-token'));

    $html = $this->actingAs($this->user)->get($tab)->assertOk()->getContent();
    expect($html)->toContain('https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit')->toContain('characters')->toContain('Read it now')->toContain('What Claude reads')
        ->toContain('Μικροί κόσμοι, γεμάτοι χρώμα και φαντασία!')->not->toContain('name="slideshow"');

    // read again: the text is fetched only when Drive says the file changed
    $exports = fn () => collect(Http::recorded())->filter(fn (array $pair) => str_contains($pair[0]->url(), '/export'))->count();
    $before = $exports();
    $this->actingAs($this->user)->post(route('clients.files.slideshow.sync', $this->client))->assertRedirect($tab)->assertSessionHas('status', 'Slideshow read again');
    expect($exports())->toBe($before);
    fakeSlidesDrive('2026-11-09T14:00:00.000Z', SLIDES_TEXT."\n\n14/11 10πμ\nAPOLLONIA");
    $this->actingAs($this->user)->post(route('clients.files.slideshow.sync', $this->client))->assertRedirect($tab);
    expect($document->fresh()->text)->toEndWith('APOLLONIA');

    // the token monitor and the accounts page have nothing to do with it; the folder card is still there to link
    $this->artisan('accounts:check-tokens')->assertSuccessful();
    $this->actingAs($this->user)->get(route('clients.accounts', $this->client))->assertOk()->assertDontSee(SLIDES_ID);
    $this->actingAs($this->user)->get($tab)->assertOk()->assertSee('name="folder"', false);

    // another file in its place forgets the old text
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => 'https://drive.google.com/file/d/otherdeck123456/view'])->assertRedirect($tab);
    expect($account->fresh()->external_id)->toBe('otherdeck123456')->and(ClientDocument::where('source_id', SLIDES_ID)->exists())->toBeFalse()
        ->and($account->documents()->sole()->name)->toBe('Dokos plan.pptx');

    $this->actingAs($this->user)->delete(route('accounts.destroy', $account))->assertRedirect($tab)->assertSessionHas('status', 'Slideshow unlinked, its text is forgotten here');
    expect(Account::where('platform', 'drive_slides')->exists())->toBeFalse()->and(ClientDocument::count())->toBe(0);
    $this->actingAs($this->user)->post(route('clients.files.slideshow.sync', $this->client))->assertNotFound();
});

test('a slideshow that can no longer be read keeps its text and says why, on the tab and in the brief', function () {
    slideshowKey();
    $account = linkedSlideshow($this->client);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/*' => Http::response(['error' => ['message' => 'File not found']], 404),
    ]);

    (new SyncClientDocuments($account))->handle(app(GoogleDrive::class));

    expect($account->fresh()->meta['error'])->toStartWith('Google Drive: file not found.')->and($account->documents()->sole()->text)->toBe(SLIDES_TEXT);
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->assertSee('Google Drive: file not found.');
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['The team\'s posts slideshow \"Dokos Home\" could not be read: Google Drive: file not found.', '7/11 SCHEDULED']);
});

test('the reference carries the slideshow with or without a folder, within its budget, and never another client\'s', function () {
    expect(ClientReference::for($this->client))->toMatchArray(['upcoming_posts' => '', 'slideshow' => null]);

    linkedSlideshow($this->client);
    linkedSlideshow($other = Client::factory()->create(), 'Other client deck');
    $reference = ClientReference::for($this->client);
    expect($reference['upcoming_posts'])->toBe("### Dokos Home (09-11-2026)\n".SLIDES_TEXT)
        ->and($reference['slideshow'])->toBe(['name' => 'Dokos Home', 'url' => 'https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit', 'synced_at' => '2026-11-09 13:10:00', 'modified' => '09-11-2026 15:08', 'chars' => mb_strlen(SLIDES_TEXT), 'error' => null])
        ->and($reference['folder'])->toBeNull()->and($reference['previous_posts'])->toBe('')
        ->and(ClientReference::for($other)['upcoming_posts'])->toContain('Other client deck')->not->toContain('SCHEDULED');

    // next to a reference folder, and cut at its budget with a note
    $this->client->accounts()->create(['platform' => 'drive_docs', 'external_id' => 'root1', 'name' => 'Dokos docs', 'access_token' => '', 'meta' => []]);
    $this->client->accounts()->where('platform', 'drive_slides')->first()->documents()->update(['text' => str_repeat('α', ClientReference::UPCOMING_TOTAL + 50)]);
    $reference = ClientReference::for($this->client);
    expect($reference['folder']['name'])->toBe('Dokos docs')
        ->and($reference['upcoming_posts'])->toEndWith('[… cut at '.ClientReference::UPCOMING_TOTAL.' characters]')
        ->and(mb_substr_count($reference['upcoming_posts'], 'α'))->toBe(ClientReference::UPCOMING_TOTAL);
});

test('get-planning-brief puts the slideshow under its heading, names it, and notes one with nothing read yet', function () {
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['write the caption briefs the same way.\nnone', 'Leave the days that already have a post in the posts slideshow free', '"posts_slideshow":null'])->assertDontSee('"note"');

    $account = linkedSlideshow($this->client);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['## The team\'s posts slideshow (posts prepared to go up)', 'write the caption briefs the same way.\n### Dokos Home (09-11-2026)\n7/11 SCHEDULED\nΜικροί κόσμοι',
            '"posts_slideshow":{"name":"Dokos Home","url":"https://docs.google.com/presentation/d/'.SLIDES_ID.'/edit"'])
        ->assertDontSee('"note"');

    $account->documents()->delete();
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee('The team\'s posts slideshow \"Dokos Home\" is linked but nothing has been read from it yet');
});

test('content:plan and the server planner read the slideshow too', function () {
    linkedSlideshow($this->client);
    $brief = tempnam(sys_get_temp_dir(), 'brief');
    file_put_contents($brief, '# Pasted brief');
    $this->mock(TrendResearcher::class)->shouldNotReceive('brief');
    $this->mock(CaptionDrafter::class)->shouldNotReceive('draft');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->once()
        ->withArgs(fn ($request, $text, $recent, $reference) => str_starts_with($reference['upcoming_posts'], "### Dokos Home (09-11-2026)\n7/11 SCHEDULED") && $reference['slideshow']['name'] === 'Dokos Home')
        ->andReturn(['posts' => [], 'warnings' => []]);
    $this->artisan("content:plan dokos --posts=1 --brief={$brief}")->assertSuccessful();

    $request = new PlanRequest(profile: $this->client->toProfileArray(), count: 1, platforms: ['instagram'], from: CarbonImmutable::parse('2027-05-02'), to: CarbonImmutable::parse('2027-05-02'));
    $post = ['date' => '2027-05-02', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => 'place', 'title' => 'Cozy', 'angle' => 'x', 'trend_signal' => null, 'why_now' => 'y', 'asset_brief' => 'z', 'caption_brief' => 'w'];
    (new ContentPlanner(fakeClaude(json_encode(['posts' => [$post]]))))->plan($request, '# Brief', [], ['upcoming_posts' => "### Dokos Home\n09/11 BLACK FRIDAY"]);
    $prompt = json_decode((string) claudeRequests()[0]['request']->getBody(), true)['messages'][0]['content'];
    expect($prompt)->toContain("write the caption briefs the same way.\n### Dokos Home\n09/11 BLACK FRIDAY");
});

test('inbox:pull reads the slideshow every hour, the client page says Claude reads it, and a limited login stays out', function () {
    Queue::fake();
    $account = linkedSlideshow($this->client);
    $this->artisan('inbox:pull')->expectsOutputToContain('Dokos Home: slideshow queued')->assertSuccessful();
    Queue::assertPushed(SyncClientDocuments::class, fn (SyncClientDocuments $job) => $job->account->is($account));

    config()->set('features.claude_api', false);
    $this->actingAs($this->user)->get(route('clients.edit', $this->client))->assertOk()->assertSee('And the posts slideshow “Dokos Home”.', false);

    $limited = User::factory()->create(['client_ids' => [Client::factory()->create()->id]]);
    $this->actingAs($limited)->post(route('clients.files.slideshow', $this->client), ['slideshow' => SLIDES_ID])->assertForbidden();
    $this->actingAs($limited)->post(route('clients.files.slideshow.sync', $this->client))->assertForbidden();
    $this->actingAs($limited)->delete(route('accounts.destroy', $account))->assertForbidden();

    $this->actingAs(User::factory()->create(['locale' => 'el']))->get(route('clients.files', $this->client))->assertOk()->assertSee('Παρουσίαση αναρτήσεων')->assertSee('Τι διαβάζει το Claude');
});

test('a slideshow inside the client\'s reference folder is read once, as the slideshow', function () {
    slideshowKey();
    $folder = $this->client->accounts()->create(['platform' => 'drive_docs', 'external_id' => 'root1', 'name' => 'Dokos docs', 'access_token' => '', 'meta' => []]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/'.SLIDES_ID.'/export*' => Http::response(SLIDES_TEXT),
        'www.googleapis.com/drive/v3/files/menu1/export*' => Http::response('Menu text'),
        'www.googleapis.com/drive/v3/files/'.SLIDES_ID.'*' => Http::response(['id' => SLIDES_ID, 'name' => 'Dokos Home', 'mimeType' => 'application/vnd.google-apps.presentation', 'modifiedTime' => '2026-11-09T13:08:16.570Z']),
        'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [
            ['id' => SLIDES_ID, 'name' => 'Dokos Home', 'mimeType' => 'application/vnd.google-apps.presentation', 'modifiedTime' => '2026-11-09T13:08:16.570Z'],
            ['id' => 'menu1', 'name' => 'Menu', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-11-01T10:00:00Z'],
        ]]),
    ]);

    // read by the folder first, then linked as the slideshow: the row moves to the slideshow
    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));
    expect($folder->documents()->pluck('source_id')->all())->toEqualCanonicalizing([SLIDES_ID, 'menu1']);
    $this->actingAs($this->user)->post(route('clients.files.slideshow', $this->client), ['slideshow' => SLIDES_ID])->assertSessionHasNoErrors();
    $slides = $this->client->accounts()->where('platform', 'drive_slides')->sole();
    expect($slides->documents()->sole()->kind)->toBe('upcoming_posts')->and($folder->documents()->pluck('source_id')->all())->toBe(['menu1']);

    // the folder's next read leaves it out, and the brief has it once, as the slideshow
    (new SyncClientDocuments($folder->fresh()))->handle(app(GoogleDrive::class));
    expect(ClientDocument::where('source_id', SLIDES_ID)->count())->toBe(1)->and($folder->fresh()->meta['file_count'])->toBe(1);
    $reference = ClientReference::for($this->client);
    expect($reference['upcoming_posts'])->toContain('7/11 SCHEDULED')->and($reference['reference'])->not->toContain('SCHEDULED')->and(collect($reference['files'])->pluck('name')->all())->toBe(['Menu']);
});
