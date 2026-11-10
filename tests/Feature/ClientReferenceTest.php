<?php

use App\Drafting\CaptionDrafter;
use App\Jobs\PullFolder;
use App\Jobs\SyncClientDocuments;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetPlanningBrief;
use App\Media\DocumentText;
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

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA', 'slug' => 'almyra']);
});

/** A service account key file like the one Google hands out, with a throwaway key, and the config pointing at it. */
function referenceKey(): void
{
    static $path;
    if (! $path) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        file_put_contents($path = sys_get_temp_dir().'/socialops-test-reference-key.json', json_encode(['client_email' => 'ops@social-ops.iam.gserviceaccount.com', 'private_key' => $pem]));
    }
    config(['services.google.service_account' => $path]);
}

/** The client's linked reference folder: an accounts row with platform drive_docs. */
function referenceFolder(Client $client, array $meta = []): Account
{
    return $client->accounts()->create(['platform' => 'drive_docs', 'external_id' => 'root1', 'name' => 'Almyra docs', 'access_token' => '', 'meta' => ['folder' => 'root1', 'folder_name' => 'Almyra docs'] + $meta]);
}

/** A document row under the folder, as the sync would file it. */
function referenceDocument(Account $folder, array $attrs): ClientDocument
{
    $document = $folder->documents()->make($attrs + ['path' => '', 'mime' => 'text/plain', 'kind' => 'reference', 'chars' => isset($attrs['text']) ? mb_strlen($attrs['text']) : 0])->forceFill(['client_id' => $folder->client_id]);
    $document->save();

    return $document;
}

/** The bytes of a Word file with these paragraphs. */
function wordFile(string ...$paragraphs): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('', array_map(fn ($p) => "<w:p><w:r><w:t>{$p}</w:t></w:r></w:p>", $paragraphs)).'</w:body></w:document>');
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('the kind of a file is guessed from its name, in Greek or English', function () {
    expect(ClientDocument::guessKind('Θεματολογία Νοεμβρίου.docx'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('ΑΝΑΡΤΉΣΕΙΣ 2025'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Ημερολόγιο περιεχομένου'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Πλάνο Δεκεμβρίου'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Λεζάντες καλοκαίρι'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Published posts 2026.xlsx'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Content calendar'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Captions EN'))->toBe('previous_posts')
        ->and(ClientDocument::guessKind('Menu 2027.docx'))->toBe('reference')
        ->and(ClientDocument::guessKind('Τιμοκατάλογος.xlsx'))->toBe('reference')
        ->and(ClientDocument::guessKind('Brand guidelines'))->toBe('reference');
});

test('the Files tab links a Drive folder after checking it as the service account, queues the first read, and unlinks it with its files', function () {
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->assertSee('Add GOOGLE_SERVICE_ACCOUNT_JSON')->assertDontSee('Read the folder now');
    $this->actingAs($this->user)->post(route('clients.files.link', $this->client), ['folder' => 'x'])->assertNotFound();

    referenceKey();
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Share the folder with ops@social-ops.iam.gserviceaccount.com (Viewer is enough) before linking it.')->assertSee('What Claude reads when it plans this client')->assertDontSee('Use the photo inbox folder');

    // the photo inbox folder of the assets page is offered with one click, and the assets page points to the Files tab
    $this->client->accounts()->create(['platform' => 'drive', 'external_id' => 'root1', 'name' => 'Almyra photos', 'access_token' => '', 'meta' => ['folder' => 'root1', 'folder_name' => 'Almyra photos']]);
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Use the photo inbox folder “Almyra photos”')->assertSee('<input type="hidden" name="folder" value="root1">', false);
    $this->actingAs($this->user)->get(route('clients.assets', $this->client))->assertOk()
        ->assertSee('Documents in this folder (previous posts, menus, briefs) are read on the Files tab.')->assertSee(route('clients.files', $this->client));

    Queue::fake();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/missing*' => Http::response(['error' => ['message' => 'File not found']], 404),
        'www.googleapis.com/drive/v3/files/root1*' => Http::response(['id' => 'root1', 'name' => 'Almyra docs', 'mimeType' => 'application/vnd.google-apps.folder']),
        'www.googleapis.com/drive/v3/files/afile*' => Http::response(['id' => 'afile', 'name' => 'menu.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
    ]);
    $this->actingAs($this->user)->post(route('clients.files.link', $this->client), ['folder' => 'https://drive.google.com/drive/folders/missing?usp=sharing'])
        ->assertRedirect(route('clients.files', $this->client))->assertSessionHasErrors(['folder' => 'Google Drive: folder not found. Share it with ops@social-ops.iam.gserviceaccount.com first.']);
    $this->actingAs($this->user)->post(route('clients.files.link', $this->client), ['folder' => 'afile'])->assertSessionHasErrors(['folder' => 'Google Drive: that link is a file, not a folder.']);
    Queue::assertNothingPushed();

    $this->actingAs($this->user)->post(route('clients.files.link', $this->client), ['folder' => 'https://drive.google.com/drive/folders/root1?usp=sharing'])
        ->assertRedirect(route('clients.files', $this->client))->assertSessionHas('status', 'Linked “Almyra docs”, its files are being read');
    $folder = $this->client->accounts()->where('platform', 'drive_docs')->sole();
    expect($folder)->toMatchArray(['external_id' => 'root1', 'name' => 'Almyra docs', 'connected_by' => $this->user->id, 'meta' => ['folder' => 'root1', 'folder_name' => 'Almyra docs'], 'token_expires_at' => null]);
    Queue::assertPushed(SyncClientDocuments::class, fn (SyncClientDocuments $job) => $job->account->is($folder));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/root1') && $request->hasHeader('Authorization', 'Bearer sa-token'));

    $html = $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->getContent();
    expect($html)->toContain('Almyra docs')->toContain('Not read yet')->toContain('Read the folder now')->toContain('Unlink')->not->toContain('name="folder"')
        ->toContain('No documents in this folder yet')->toContain('No designs in this folder yet');
    $this->actingAs($this->user)->post(route('clients.files.sync', $this->client))->assertRedirect(route('clients.files', $this->client))->assertSessionHas('status', 'Reading the folder now, the list refreshes in a minute or two');
    Queue::assertPushed(SyncClientDocuments::class, 2);

    // the token monitor and the accounts page have nothing to do with it (no token, no expiry)
    $this->artisan('accounts:check-tokens')->assertSuccessful();
    $this->actingAs($this->user)->get(route('clients.accounts', $this->client))->assertOk()->assertDontSee('Almyra docs');

    // unlinking drops the folder and every document read from it; nothing changes on Drive
    referenceDocument($folder, ['source_id' => 'd1', 'name' => 'Menu.docx', 'text' => 'Mojito 9']);
    $this->actingAs($this->user)->delete(route('accounts.destroy', $folder))->assertRedirect(route('clients.files', $this->client))->assertSessionHas('status', 'Folder unlinked, its files are forgotten here');
    expect(Account::where('platform', 'drive_docs')->exists())->toBeFalse()->and(ClientDocument::count())->toBe(0)->and(Account::where('platform', 'drive')->exists())->toBeTrue(); // the photo inbox folder stays watched
    $this->actingAs($this->user)->post(route('clients.files.sync', $this->client))->assertNotFound();
});

test('the sync reads the folder and its subfolders as text, guesses the kinds, keeps the team\'s choice, drops gone files and keeps going past one failure', function () {
    referenceKey();
    $folder = referenceFolder($this->client);
    $round = 1;
    $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    Http::fake(function (Request $request) use (&$round, $docx) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
            str_contains($url, "q='root1' in parents and trashed = false") => Http::response(['files' => array_values(array_filter([
                ['id' => 'sub1', 'name' => 'Παλιά', 'mimeType' => 'application/vnd.google-apps.folder'],
                ['id' => 'gdoc', 'name' => 'Θεματολογία 2026', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => $round === 1 ? '2026-11-01T10:00:00.000Z' : '2026-11-05T10:00:00.000Z'],
                ['id' => 'gsheet', 'name' => 'Price list', 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'modifiedTime' => '2026-11-02T10:00:00.000Z'],
                ['id' => 'docx1', 'name' => 'Brief.docx', 'mimeType' => $docx, 'modifiedTime' => '2026-10-20T10:00:00.000Z', 'size' => '12345'],
                ['id' => 'huge', 'name' => 'Photo archive.xlsx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'modifiedTime' => '2026-10-22T10:00:00.000Z', 'size' => (string) (40 * 1024 * 1024)],
                ['id' => 'photo', 'name' => 'pool.jpg', 'mimeType' => 'image/jpeg', 'modifiedTime' => '2026-10-20T10:00:00.000Z', 'size' => '999', 'hasThumbnail' => true],
                ['id' => 'clip', 'name' => 'sunset.mp4', 'mimeType' => 'video/mp4', 'modifiedTime' => '2026-10-20T10:00:00.000Z', 'size' => '999', 'hasThumbnail' => true],
                ['id' => 'song', 'name' => 'jingle.mp3', 'mimeType' => 'audio/mpeg', 'modifiedTime' => '2026-10-20T10:00:00.000Z', 'size' => '999'],
                ['id' => 'short', 'name' => 'Shortcut to menu', 'mimeType' => 'application/vnd.google-apps.shortcut', 'shortcutDetails' => ['targetId' => 'x']],
                ['id' => 'form', 'name' => 'Booking form', 'mimeType' => 'application/vnd.google-apps.form'],
                $round === 1 ? ['id' => 'pdf1', 'name' => 'Menu.pdf', 'mimeType' => 'application/pdf', 'modifiedTime' => '2026-10-21T10:00:00.000Z', 'size' => '5000', 'hasThumbnail' => true] : null,
            ]))]),
            str_contains($url, "q='sub1' in parents and trashed = false") => Http::response(['files' => [
                ['id' => 'old1', 'name' => 'Αναρτήσεις 2025.docx', 'mimeType' => $docx, 'modifiedTime' => '2025-12-31T10:00:00.000Z', 'size' => '2222'],
            ]]),
            str_contains($url, '/files/gdoc/export') => Http::response($round === 1 ? "Post 1\nPost 2" : "Post 1\nPost 2\nPost 3"),
            str_contains($url, '/files/gsheet/export') => Http::response("Item,Price\nMojito,9"),
            str_contains($url, '/files/docx1?alt=media') => Http::response(wordFile('Tone: warm', 'Never say cheap')),
            str_contains($url, '/files/old1?alt=media') => Http::response(wordFile('Καλοκαίρι 2025: cocktails στο ηλιοβασίλεμα')),
            default => Http::response(['error' => ['message' => "unexpected {$url}"]], 500),
        };
    });

    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    $documents = $folder->documents()->orderBy('source_id')->get()->keyBy('source_id');
    // the photo, the video and the PDF are designs now (D25): listed, never downloaded, waiting for Claude; audio is still skipped
    expect($documents->keys()->all())->toBe(['clip', 'docx1', 'gdoc', 'gsheet', 'huge', 'old1', 'pdf1', 'photo'])
        ->and($documents['huge'])->toMatchArray(['text' => null, 'chars' => 0, 'error' => DocumentText::TOO_BIG]) // never downloaded: 40 MB would be read whole in memory
        ->and($documents['gdoc'])->toMatchArray(['name' => 'Θεματολογία 2026', 'path' => '', 'kind' => 'previous_posts', 'kind_set_by_user' => false, 'text' => "Post 1\nPost 2", 'chars' => 13, 'error' => null, 'size' => null])
        ->and($documents['gdoc']->modified_at->utc()->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($documents['gsheet'])->toMatchArray(['kind' => 'reference', 'text' => "Item,Price\nMojito,9", 'chars' => 19])
        ->and($documents['docx1'])->toMatchArray(['kind' => 'reference', 'text' => "Tone: warm\nNever say cheap", 'size' => 12345])
        ->and($documents['old1'])->toMatchArray(['name' => 'Αναρτήσεις 2025.docx', 'path' => 'Παλιά', 'kind' => 'previous_posts', 'text' => 'Καλοκαίρι 2025: cocktails στο ηλιοβασίλεμα'])
        ->and($documents['pdf1'])->toMatchArray(['kind' => 'design', 'text' => null, 'chars' => 0, 'error' => null, 'described_at' => null, 'fetched_at' => null])
        ->and($documents['photo'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => null])
        ->and($documents['clip'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => null])
        ->and($folder->fresh()->meta)->toMatchArray(['folder' => 'root1', 'file_count' => 5, 'design_count' => 3, 'capped' => false, 'error' => null])
        ->and($folder->fresh()->meta['synced_at'])->not->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), "q='root1' in parents and trashed = false") && str_contains($request->url(), 'fields=nextPageToken%2Cfiles%28id%2Cname%2CmimeType%2CmodifiedTime%2Csize%2CshortcutDetails%2ChasThumbnail%2CthumbnailLink%29') && str_contains($request->url(), 'supportsAllDrives=true') && str_contains($request->url(), 'includeItemsFromAllDrives=true'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/gdoc/export?mimeType=text%2Fplain'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/files/gsheet/export?mimeType=text%2Fcsv'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/files/photo') || str_contains($request->url(), '/files/clip') || str_contains($request->url(), '/files/pdf1') || str_contains($request->url(), '/files/song') || str_contains($request->url(), '/files/short') || str_contains($request->url(), '/files/form') || str_contains($request->url(), '/files/huge'));

    // the team marks the price list "not used"; on the next read the Doc changed, the Word file did not, the PDF is gone
    $documents['gsheet']->update(['kind' => 'ignored', 'kind_set_by_user' => true]);
    $round = 2;
    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    $documents = $folder->documents()->orderBy('source_id')->get()->keyBy('source_id');
    expect($documents->keys()->all())->toBe(['clip', 'docx1', 'gdoc', 'gsheet', 'huge', 'old1', 'photo'])
        ->and($documents['gdoc']->text)->toBe("Post 1\nPost 2\nPost 3")
        ->and($documents['gsheet']->kind)->toBe('ignored')
        ->and($folder->fresh()->meta)->toMatchArray(['file_count' => 5, 'design_count' => 2])
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/files/docx1?alt=media'))->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), '/files/gdoc/export'))->count())->toBe(2);

    // the Files tab shows it all: previous posts first, then reference, then not used
    $html = $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->getContent();
    expect($html)->toContain('Last read '.now()->format('d-m-Y'))->toContain(', 5 documents and 2 designs')->toContain('Too big to read: over 25 MB')->toContain('Google Sheets: only the first sheet is read.')->toContain('Παλιά</p>')
        ->and(strpos($html, 'Θεματολογία 2026'))->toBeLessThan(strpos($html, 'Brief.docx'))
        ->and(strpos($html, 'Brief.docx'))->toBeLessThan(strpos($html, 'Price list'));
});

test('a folder that cannot be listed keeps its documents and says why', function () {
    referenceKey();
    $folder = referenceFolder($this->client, ['synced_at' => '2026-11-08 10:00:00', 'file_count' => 1]);
    referenceDocument($folder, ['source_id' => 'd1', 'name' => 'Menu.docx', 'text' => 'Mojito 9']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files?*' => Http::response(['error' => ['message' => 'File not found']], 404),
    ]);

    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    expect($folder->fresh()->meta)->toMatchArray(['error' => 'Google Drive: folder not found. Share it with ops@social-ops.iam.gserviceaccount.com first.', 'file_count' => 1])
        ->and($folder->documents()->count())->toBe(1);
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->assertSee('folder not found. Share it with');
});

test('the reference for the planner keeps the newest files within the budgets, leaves out errors and unused files, and never another client\'s', function () {
    $folder = referenceFolder($this->client, ['synced_at' => '2026-11-09 10:00:00']);
    referenceDocument($folder, ['source_id' => 'a', 'name' => 'Plan October', 'kind' => 'previous_posts', 'modified_at' => '2026-10-01 10:00', 'text' => str_repeat('A', 13000)]);
    referenceDocument($folder, ['source_id' => 'b', 'name' => 'Plan November', 'kind' => 'previous_posts', 'modified_at' => '2026-11-01 10:00', 'text' => str_repeat('B', 13000)]);
    referenceDocument($folder, ['source_id' => 'c', 'name' => 'Plan September', 'kind' => 'previous_posts', 'modified_at' => '2026-09-01 10:00', 'text' => str_repeat('C', 2000)]);
    referenceDocument($folder, ['source_id' => 'r1', 'name' => 'Menu', 'path' => 'Facts', 'kind' => 'reference', 'modified_at' => '2026-08-01 10:00', 'text' => str_repeat('Q', 7000)]);
    referenceDocument($folder, ['source_id' => 'r2', 'name' => 'Rooms', 'kind' => 'reference', 'modified_at' => null, 'text' => str_repeat('W', 3000)]);
    referenceDocument($folder, ['source_id' => 'x', 'name' => 'Old invoice', 'kind' => 'ignored', 'modified_at' => '2026-11-02 10:00', 'text' => 'ignored text']);
    referenceDocument($folder, ['source_id' => 'e', 'name' => 'Menu.doc', 'mime' => 'application/msword', 'kind' => 'reference', 'modified_at' => '2026-11-03 10:00', 'error' => DocumentText::OLD_OFFICE]);
    $other = Client::factory()->create();
    referenceDocument(referenceFolder($other), ['source_id' => 'o', 'name' => 'Other client secrets', 'kind' => 'reference', 'text' => 'SECRET']);

    $reference = ClientReference::for($this->client);

    expect($reference['folder'])->toBe(['name' => 'Almyra docs', 'synced_at' => '2026-11-09 10:00:00'])
        ->and($reference['previous_posts'])->toStartWith("### Plan November (01-11-2026)\nBBBB")->toContain("\n[… cut at 12000 characters]\n\n### Plan October (01-10-2026)\nAAAA")->not->toContain('Plan September')->not->toContain('C')
        ->and(mb_substr_count($reference['previous_posts'], 'B'))->toBe(12000)
        ->and(mb_substr_count($reference['previous_posts'], 'A'))->toBe(12000)
        ->and($reference['reference'])->toStartWith("### Menu (01-08-2026)\nQQQQ")->toContain("[… cut at 6000 characters]\n\n### Rooms (–)\nWWW")->not->toContain('ignored text')->not->toContain('Menu.doc')->not->toContain('SECRET')
        ->and(mb_substr_count($reference['reference'], 'Q'))->toBe(6000)
        ->and(mb_substr_count($reference['reference'], 'W'))->toBe(3000)
        ->and(array_column($reference['files'], 'name'))->toBe(['Menu.doc', 'Old invoice', 'Plan November', 'Plan October', 'Plan September', 'Menu', 'Rooms'])
        ->and($reference['files'][0])->toBe(['name' => 'Menu.doc', 'path' => '', 'kind' => 'reference', 'chars' => 0, 'modified' => '03-11-2026', 'error' => DocumentText::OLD_OFFICE])
        ->and($reference['files'][5])->toMatchArray(['path' => 'Facts', 'chars' => 7000, 'modified' => '01-08-2026', 'error' => null]);

    // the total budget for previous posts: a third file only gets what is left
    referenceDocument($folder, ['source_id' => 'd', 'name' => 'Plan December', 'kind' => 'previous_posts', 'modified_at' => '2026-12-01 10:00', 'text' => str_repeat('Z', 500)]);
    $previous = ClientReference::for($this->client)['previous_posts'];
    expect(mb_substr_count($previous, 'Z'))->toBe(500)->and(mb_substr_count($previous, 'B'))->toBe(12000)->and(mb_substr_count($previous, 'A'))->toBe(11500)->and($previous)->toContain('[… cut at 11500 characters]');

    expect(ClientReference::for(Client::factory()->create()))->toBe(['products' => '', 'upcoming_posts' => '', 'slideshow' => null, 'previous_posts' => '', 'reference' => '', 'designs' => '', 'designs_described' => 0, 'designs_waiting' => 0, 'files' => [], 'folder' => null]);
});

test('get-planning-brief carries the previous posts and the reference files under their headings, lists the files, and notes a folder with nothing read', function () {
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 3])->assertOk()
        ->assertSee(['the kind of post the client is used to.\nnone', 'say so in notes_for_reviewer.\nnone', 'Prefer what the client', '"reference_folder":null', '"reference_files":[]'])->assertDontSee('"note"');

    $folder = referenceFolder($this->client, ['synced_at' => '2026-11-09 10:00:00', 'file_count' => 0]);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 3])->assertOk()
        ->assertSee(['"reference_folder":{"name":"Almyra docs","synced_at":"2026-11-09 10:00:00"}', 'is linked but nothing has been read from it yet']);

    referenceDocument($folder, ['source_id' => 'gdoc', 'name' => 'Θεματολογία 2026', 'kind' => 'previous_posts', 'modified_at' => '2026-11-01 10:00', 'text' => "Post 1\nPost 2"]);
    referenceDocument($folder, ['source_id' => 'gsheet', 'name' => 'Price list', 'path' => 'Facts', 'kind' => 'reference', 'modified_at' => '2026-11-02 10:00', 'text' => "Item,Price\nMojito,9"]);
    referenceDocument($folder, ['source_id' => 'doc1', 'name' => 'Menu.doc', 'mime' => 'application/msword', 'modified_at' => '2026-11-03 10:00', 'error' => DocumentText::OLD_OFFICE]);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 3])->assertOk()
        ->assertSee(['the kind of post the client is used to.\n### Θεματολογία 2026 (01-11-2026)\nPost 1\nPost 2', 'say so in notes_for_reviewer.\n### Price list (02-11-2026)\nItem,Price\nMojito,9',
            '"reference_files":[{"name":"Menu.doc","path":"","kind":"reference","chars":0,"modified":"03-11-2026","error":"'.DocumentText::OLD_OFFICE.'"},{"name":"Price list","path":"Facts","kind":"reference","chars":19,"modified":"02-11-2026","error":null},{"name":"Θεματολογία 2026"'])
        ->assertDontSee('"note"');

    $folder->forceFill(['meta' => $folder->meta + ['error' => 'Google Drive: folder not found. Share it with ops@social-ops.iam.gserviceaccount.com first.']])->save();
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 3])->assertOk()->assertSee('could not be read: Google Drive: folder not found');
});

test('content:plan hands the planner the client reference next to the recent topics', function () {
    $folder = referenceFolder($this->client);
    referenceDocument($folder, ['source_id' => 'gdoc', 'name' => 'Θεματολογία 2026', 'kind' => 'previous_posts', 'modified_at' => '2026-11-01 10:00', 'text' => "Post 1\nPost 2"]);
    referenceDocument($folder, ['source_id' => 'r', 'name' => 'Menu', 'kind' => 'reference', 'text' => 'Mojito 9']);
    $brief = tempnam(sys_get_temp_dir(), 'brief');
    file_put_contents($brief, '# Pasted brief');

    $this->mock(TrendResearcher::class)->shouldNotReceive('brief');
    $this->mock(CaptionDrafter::class)->shouldNotReceive('draft');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->once()
        ->withArgs(fn ($request, $text, $recent, $reference) => $text === '# Pasted brief' && $recent === [] && $reference['previous_posts'] === "### Θεματολογία 2026 (01-11-2026)\nPost 1\nPost 2" && $reference['reference'] === "### Menu (–)\nMojito 9" && count($reference['files']) === 2)
        ->andReturn(['posts' => [], 'warnings' => []]);

    $this->artisan("content:plan almyra --posts=1 --brief={$brief}")->assertSuccessful();
});

test('the planner puts the reference in the prompt', function () {
    $request = new PlanRequest(profile: $this->client->toProfileArray(), count: 1, platforms: ['instagram'], from: CarbonImmutable::parse('2027-05-02'), to: CarbonImmutable::parse('2027-05-02'));
    $post = ['date' => '2027-05-02', 'platforms' => ['instagram'], 'format' => 'reel', 'pillar' => 'place', 'title' => 'Morning swim', 'angle' => 'x', 'trend_signal' => null, 'why_now' => 'y', 'asset_brief' => 'z', 'caption_brief' => 'w'];

    (new ContentPlanner(fakeClaude(json_encode(['posts' => [$post]]))))->plan($request, '# Brief', [], ['previous_posts' => "### Plan\nPost 1", 'reference' => "### Menu\nMojito 9"]);

    $prompt = json_decode((string) claudeRequests()[0]['request']->getBody(), true)['messages'][0]['content'];
    expect($prompt)->toContain("the kind of post the client is used to.\n### Plan\nPost 1")->toContain("say so in notes_for_reviewer.\n### Menu\nMojito 9");
});

test('inbox:pull queues a read of every reference folder next to the photo folders', function () {
    Queue::fake();
    $folder = referenceFolder($this->client);
    Client::factory()->create()->accounts()->create(['platform' => 'drive', 'external_id' => 'f', 'name' => 'Photos', 'access_token' => '', 'meta' => ['folder' => 'f', 'folder_name' => 'Photos']]);

    $this->artisan('inbox:pull')->expectsOutputToContain('Almyra docs: documents queued')->expectsOutputToContain('Photos: queued')->assertSuccessful();

    Queue::assertPushed(SyncClientDocuments::class, fn (SyncClientDocuments $job) => $job->account->is($folder));
    Queue::assertPushed(PullFolder::class, 1);
});

test('the Files tab lists the files with their kind, changes a kind for good, counts the read files, and keeps a limited login out', function () {
    $folder = referenceFolder($this->client, ['synced_at' => '2026-11-09 10:00:00', 'file_count' => 3]);
    $plan = referenceDocument($folder, ['source_id' => 'gdoc', 'name' => 'Θεματολογία 2026', 'kind' => 'previous_posts', 'modified_at' => '2026-11-01 10:00', 'text' => "Post 1\nPost 2"]);
    $menu = referenceDocument($folder, ['source_id' => 'docx1', 'name' => 'Menu.docx', 'path' => 'Facts/2027', 'kind' => 'reference', 'modified_at' => '2026-10-20 10:00', 'text' => str_repeat('x', 1234)]);
    $old = referenceDocument($folder, ['source_id' => 'doc1', 'name' => 'Menu.doc', 'mime' => 'application/msword', 'kind' => 'reference', 'error' => DocumentText::OLD_OFFICE]);
    $other = Client::factory()->create();
    $foreign = referenceDocument(referenceFolder($other), ['source_id' => 'o', 'name' => 'Other.docx', 'text' => 'other']);

    $html = $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->getContent();
    expect($html)->toContain('Last read 09-11-2026 10:00, 3 files')->toContain('Θεματολογία 2026')->toContain('Facts/2027')->toContain('13 characters read')->toContain('1,234 characters read')
        ->toContain('Old Office format: save it as .docx/.xlsx')->toContain('01-11-2026')->toContain('20-10-2026')
        ->toContain('Files<span')->toContain('>2</span>') // two files read, the old .doc not
        ->toContain('<option value="previous_posts" selected>Previous posts</option>')->toContain('<option value="reference" selected>Reference</option>')->toContain('Not used')
        ->not->toContain('Other.docx');

    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $menu]), ['kind' => 'previous_posts'])->assertRedirect(route('clients.files', $this->client));
    expect($menu->fresh())->toMatchArray(['kind' => 'previous_posts', 'kind_set_by_user' => true]);
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $old]), ['kind' => 'secret'])->assertSessionHasErrors('kind');
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $old]), ['kind' => 'design'])->assertSessionHasErrors('kind'); // a document is no design
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $foreign]), ['kind' => 'ignored'])->assertNotFound();
    expect($foreign->fresh()->kind)->toBe('reference');

    // the client page's MCP box says what Claude reads
    config()->set('features.claude_api', false);
    $this->actingAs($this->user)->get(route('clients.edit', $this->client))->assertOk()->assertSee('Claude also reads 2 files from the client&#039;s Drive folder (2 with previous posts).', false)->assertSee(route('clients.files', $this->client));
    $this->actingAs($this->user)->get(route('clients.edit', Client::factory()->create()))->assertOk()->assertSee('Link the client&#039;s Drive folder on the Files tab', false);

    // a login limited to another client sees nothing of this one
    $limited = User::factory()->create(['client_ids' => [$other->id]]);
    $this->actingAs($limited)->get(route('clients.files', $this->client))->assertForbidden();
    $this->actingAs($limited)->post(route('clients.files.sync', $this->client))->assertForbidden();
    $this->actingAs($limited)->patch(route('clients.files.kind', [$this->client, $plan]), ['kind' => 'ignored'])->assertForbidden();
    $this->actingAs($limited)->delete(route('accounts.destroy', $folder))->assertForbidden();
    $this->actingAs($limited)->get(route('clients.files', $other))->assertOk()->assertSee('Other.docx');

    // in Greek the Assets tab reads "Υλικό", so the Files tab can read "Αρχεία"
    $this->actingAs(User::factory()->create(['locale' => 'el']))->get(route('clients.files', $this->client))->assertOk()->assertSee('Υλικό')->assertSee('Προηγούμενες αναρτήσεις')->assertSee('Παλιά μορφή Office');
});
