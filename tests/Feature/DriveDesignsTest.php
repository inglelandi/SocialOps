<?php

use App\Jobs\SyncClientDocuments;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetDesign;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\SaveDesignDescription;
use App\Media\GoogleDrive;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\User;
use App\Planning\ClientReference;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Designs in the client's reference folder (D25): listed by the sync, never downloaded, described once by Claude through the
// connector from Drive's preview, which the app passes on and never keeps.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Fuse', 'code' => 'FUSE', 'slug' => 'fuse']);
    Storage::fake(config('filesystems.default'));
});

/** A throwaway service account key, and the config pointing at it. */
function designsKey(): void
{
    static $path;
    if (! $path) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        file_put_contents($path = sys_get_temp_dir().'/socialops-test-designs-key.json', json_encode(['client_email' => 'ops@social-ops.iam.gserviceaccount.com', 'private_key' => $pem]));
    }
    config(['services.google.service_account' => $path]);
}

function designsFolder(Client $client, array $meta = []): Account
{
    return $client->accounts()->create(['platform' => 'drive_docs', 'external_id' => 'root1', 'name' => 'Fuse x Inglelandi', 'access_token' => '', 'meta' => ['folder' => 'root1'] + $meta]);
}

/** A design row as the sync files it: waiting unless text or error say otherwise. */
function designRow(Account $folder, array $attrs): ClientDocument
{
    $row = $folder->documents()->make($attrs + ['name' => $attrs['source_id'].'.jpg', 'path' => '', 'mime' => 'image/jpeg', 'kind' => 'design', 'chars' => isset($attrs['text']) ? mb_strlen($attrs['text']) : 0])->forceFill(['client_id' => $folder->client_id]);
    $row->save();

    return $row;
}

/** A tool called over HTTP with a bearer token: the JSON-RPC result. */
function designsMcp(string $token, string $tool, array $arguments = []): array
{
    return test()->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()->json('result');
}

/** Drive's metadata and thumbnail for a file, and the token. */
function fakePreview(string $id = 'f1', string $bytes = "\xFF\xD8\xFFJPEGBYTES"): void
{
    designsKey();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        "www.googleapis.com/drive/v3/files/{$id}?*" => Http::response(['hasThumbnail' => true, 'thumbnailLink' => "https://lh3.googleusercontent.com/drive-storage/{$id}-thumb=s220"]),
        "lh3.googleusercontent.com/drive-storage/{$id}-thumb=*" => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

test('the sync lists designs six levels deep without downloading them, marks the ones Drive cannot preview, and describes nothing twice', function () {
    designsKey();
    $folder = designsFolder($this->client);
    $round = 1;
    $listings = [];
    Http::fake(function (Request $request) use (&$round, &$listings) {
        $url = urldecode($request->url());
        $children = fn (string $id) => str_contains($url, "q='{$id}' in parents and trashed = false");
        $folderOf = fn (string $id, string $name) => ['id' => $id, 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if (preg_match("~q='(\w+)' in parents~", $url, $m)) {
            $listings[] = $m[1];
        }

        return match (true) {
            str_contains($url, 'oauth2.googleapis.com/token') => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
            $children('root1') => Http::response(['files' => [
                $folderOf('f1', 'Instagram Posts (12/month)'),
                ['id' => 'logo', 'name' => 'Logo 2026.ai', 'mimeType' => 'application/postscript', 'modifiedTime' => '2026-01-10T10:00:00.000Z', 'size' => '73400320', 'hasThumbnail' => true, 'thumbnailLink' => 'https://lh3.googleusercontent.com/drive-storage/logo=s220'],
                ['id' => 'menu', 'name' => 'Menu.pdf', 'mimeType' => 'application/pdf', 'modifiedTime' => '2026-02-10T10:00:00.000Z', 'size' => '5000', 'hasThumbnail' => true],
                ['id' => 'indd', 'name' => 'Brochure.indd', 'mimeType' => 'application/x-indesign', 'modifiedTime' => '2026-03-10T10:00:00.000Z', 'size' => '9000', 'hasThumbnail' => false],
                ['id' => 'psd', 'name' => 'Banner.psd', 'mimeType' => 'application/octet-stream', 'modifiedTime' => '2026-03-11T10:00:00.000Z', 'size' => '9000', 'hasThumbnail' => $round === 2],
                ['id' => 'notes', 'name' => 'notes.txt', 'mimeType' => 'text/plain', 'modifiedTime' => '2026-03-12T10:00:00.000Z', 'size' => '5'],
            ]]),
            $children('f1') => Http::response(['files' => [$folderOf('f2', '2. October 2026')]]),
            $children('f2') => Http::response(['files' => [$folderOf('f3', 'Posts')]]),
            $children('f3') => Http::response(['files' => [
                $folderOf('f4', 'Next posts'),
                ['id' => 'jpg', 'name' => 'Halloween.jpg', 'mimeType' => 'image/jpeg', 'modifiedTime' => $round === 1 ? '2026-10-20T10:00:00.000Z' : '2026-10-25T10:00:00.000Z', 'size' => '800000', 'hasThumbnail' => true],
                ['id' => 'mov', 'name' => 'Reel.mov', 'mimeType' => 'video/quicktime', 'modifiedTime' => '2026-10-21T10:00:00.000Z', 'size' => (string) (150 * 1024 * 1024), 'hasThumbnail' => true],
                ['id' => 'mp4', 'name' => 'Huge.mp4', 'mimeType' => 'video/mp4', 'modifiedTime' => '2026-10-22T10:00:00.000Z', 'size' => '6000000000', 'hasThumbnail' => true],
            ]]),
            $children('f4') => Http::response(['files' => [$folderOf('f5', 'A')]]),
            $children('f5') => Http::response(['files' => [$folderOf('f6', 'B')]]),
            $children('f6') => Http::response(['files' => [$folderOf('f7', 'C'), ['id' => 'deep6', 'name' => 'deep.png', 'mimeType' => 'image/png', 'modifiedTime' => '2026-10-23T10:00:00.000Z', 'hasThumbnail' => true]]]),
            $children('f7') => Http::response(['files' => [['id' => 'deep7', 'name' => 'too deep.png', 'mimeType' => 'image/png', 'hasThumbnail' => true]]]),
            str_contains($url, '/files/notes?alt=media') => Http::response('Open 10:00'),
            default => Http::response(['error' => ['message' => "unexpected {$url}"]], 500),
        };
    });

    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    $rows = $folder->documents()->get()->keyBy('source_id');
    expect($rows->keys()->sort()->values()->all())->toBe(['deep6', 'indd', 'jpg', 'logo', 'menu', 'mov', 'mp4', 'notes', 'psd'])
        ->and($rows['logo'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => null, 'described_at' => null, 'size' => 73400320])
        ->and($rows['menu'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => null]) // a PDF: described from its first page, no longer an error
        ->and($rows['jpg'])->toMatchArray(['kind' => 'design', 'path' => 'Instagram Posts (12/month)/2. October 2026/Posts'])
        ->and($rows['mov'])->toMatchArray(['kind' => 'design', 'error' => null])
        ->and($rows['mp4']->size)->toBe(SyncClientDocuments::SIZE_MAX) // bigger than the column, kept at its top
        ->and($rows['deep6'])->toMatchArray(['kind' => 'design', 'path' => 'Instagram Posts (12/month)/2. October 2026/Posts/Next posts/A/B'])
        ->and($rows['indd'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => ClientDocument::NO_PREVIEW])
        ->and($rows['psd'])->toMatchArray(['kind' => 'design', 'error' => ClientDocument::NO_PREVIEW]) // by its extension
        ->and($rows['notes'])->toMatchArray(['kind' => 'reference', 'text' => 'Open 10:00'])
        ->and($folder->fresh()->meta)->toMatchArray(['file_count' => 1, 'design_count' => 8, 'capped' => false])
        ->and($listings)->not->toContain('f7'); // the sixth level's files are read, its subfolders not walked
    // nothing of a design is ever downloaded: no alt=media, no thumbnail fetched by the sync
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'alt=media') && ! str_contains($request->url(), '/files/notes'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'googleusercontent.com'));

    // Claude describes the logo and the Halloween post; the team marks the PDF "Not used"
    $rows['logo']->forceFill(['text' => 'The Fuse logo in gold.', 'chars' => 22, 'described_at' => now()])->save();
    $rows['jpg']->forceFill(['text' => 'Halloween party poster, 31-10.', 'chars' => 30, 'described_at' => now()])->save();
    $rows['menu']->update(['kind' => 'ignored', 'kind_set_by_user' => true]);

    // the next read: the logo is unchanged, the Halloween file changed on Drive, the Photoshop file has a preview now
    $round = 2;
    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    $rows = $folder->documents()->get()->keyBy('source_id');
    expect($rows['logo'])->toMatchArray(['text' => 'The Fuse logo in gold.', 'error' => null])->and($rows['logo']->described_at)->not->toBeNull()
        ->and($rows['jpg'])->toMatchArray(['kind' => 'design', 'text' => null, 'chars' => 0, 'described_at' => null, 'error' => null])
        ->and($rows['menu'])->toMatchArray(['kind' => 'ignored', 'text' => null])
        ->and($rows['psd'])->toMatchArray(['kind' => 'design', 'error' => null])
        ->and($rows['indd']->error)->toBe(ClientDocument::NO_PREVIEW);

    // what list-claude-jobs offers: waiting designs only, never the described logo, the ignored PDF or the InDesign file without a preview
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()
        ->assertSee(['"name":"Halloween.jpg"', '"name":"Banner.psd"', '"name":"Reel.mov"', '"name":"deep.png"', '"designs_waiting":{"fuse":5}'])
        ->assertDontSee(['"name":"Logo 2026.ai"', '"name":"Menu.pdf"', '"name":"Brochure.indd"', '"name":"notes.txt"']);
});

test('a PDF read as a document before becomes a waiting design; a team-set Not used stays; a gone file is dropped', function () {
    designsKey();
    $folder = designsFolder($this->client);
    $folder->documents()->make(['source_id' => 'pdf1', 'name' => 'Menu.pdf', 'path' => '', 'mime' => 'application/pdf', 'kind' => 'reference', 'chars' => 0, 'error' => 'PDF files are not read: open it in Google Docs or save it as Word', 'modified_at' => '2026-10-21 12:00'])->forceFill(['client_id' => $this->client->id])->save();
    designRow($folder, ['source_id' => 'skip', 'name' => 'Old flyer.jpg', 'kind' => 'ignored', 'kind_set_by_user' => true]);
    designRow($folder, ['source_id' => 'gone', 'name' => 'Deleted.jpg']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [
            ['id' => 'pdf1', 'name' => 'Menu.pdf', 'mimeType' => 'application/pdf', 'modifiedTime' => '2026-10-21T10:00:00.000Z', 'hasThumbnail' => true],
            ['id' => 'skip', 'name' => 'Old flyer.jpg', 'mimeType' => 'image/jpeg', 'hasThumbnail' => true],
        ]]),
    ]);

    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));

    $rows = $folder->documents()->get()->keyBy('source_id');
    expect($rows->keys()->sort()->values()->all())->toBe(['pdf1', 'skip'])
        ->and($rows['pdf1'])->toMatchArray(['kind' => 'design', 'text' => null, 'error' => null])
        ->and($rows['skip'])->toMatchArray(['kind' => 'ignored', 'kind_set_by_user' => true]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'alt=media'));
});

test('the listing reads a folder\'s own files before its subfolders and stops at 1000 files, saying so on the tab', function () {
    designsKey();
    $folder = designsFolder($this->client);
    $files = array_map(fn ($i) => ['id' => "img{$i}", 'name' => sprintf('Post %04d.jpg', $i), 'mimeType' => 'image/jpeg', 'hasThumbnail' => true], range(1, 1001));
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [['id' => 'sub', 'name' => 'Archive', 'mimeType' => 'application/vnd.google-apps.folder'], ...$files]]),
    ]);

    $listing = app(GoogleDrive::class)->documents($folder);

    expect($listing['capped'])->toBeTrue()->and($listing['files'])->toHaveCount(GoogleDrive::DOCUMENTS_MAX)
        ->and($listing['files'][0])->toMatchArray(['id' => 'img1', 'design' => true, 'preview' => true, 'path' => ''])
        ->and(GoogleDrive::DOCUMENTS_MAX)->toBe(1000)->and(GoogleDrive::DOCUMENTS_DEPTH)->toBe(6);
    Http::assertSentCount(2); // the token and one page of the folder: the subfolder is never listed once the cap is hit

    $folder->forceFill(['meta' => $folder->meta + ['synced_at' => '2026-11-09 10:00:00', 'file_count' => 0, 'design_count' => 1000, 'capped' => true]])->save();
    $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Last read 09-11-2026 10:00, 0 documents and 1000 designs')
        ->assertSee('The folder holds more files than the 1,000 read here: its own files first, then its subfolders, 6 levels deep at most.');
});

test('a design\'s preview comes from a fresh thumbnail link at the asked size with the bearer token, and nothing is kept', function () {
    fakePreview('f1');

    $preview = app(GoogleDrive::class)->preview('f1', 1024);

    expect($preview)->toBe(['bytes' => "\xFF\xD8\xFFJPEGBYTES", 'mime' => 'image/jpeg']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/drive/v3/files/f1?') && str_contains(urldecode($request->url()), 'fields=hasThumbnail,thumbnailLink') && str_contains($request->url(), 'supportsAllDrives=true'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://lh3.googleusercontent.com/drive-storage/f1-thumb=s1024' && $request->hasHeader('Authorization', 'Bearer sa-token'));
    expect(Storage::allFiles())->toBe([])->and(cache()->has('google-drive-token'))->toBeTrue(); // only the access token is cached, as before

    expect(GoogleDrive::sized('https://lh3.googleusercontent.com/drive-storage/abc=s220', 400))->toBe('https://lh3.googleusercontent.com/drive-storage/abc=s400')
        ->and(GoogleDrive::sized('https://lh3.googleusercontent.com/abc=s220-c', 1024))->toBe('https://lh3.googleusercontent.com/abc=s1024')
        ->and(GoogleDrive::sized('https://docs.google.com/feeds/vt?gd=true&id=abc&v=1&sz=s220', 400))->toBe('https://docs.google.com/feeds/vt?gd=true&id=abc&v=1&sz=s400')
        ->and(GoogleDrive::sized('https://lh3.googleusercontent.com/abc', 400))->toBe('https://lh3.googleusercontent.com/abc=s400');
});

test('a preview fails plainly without a thumbnail, on a failed fetch, on something that is no image, or a link outside Google', function () {
    designsKey();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/none?*' => Http::response(['hasThumbnail' => false]),
        'www.googleapis.com/drive/v3/files/denied?*' => Http::response(['hasThumbnail' => true, 'thumbnailLink' => 'https://lh3.googleusercontent.com/denied=s220']),
        'www.googleapis.com/drive/v3/files/html?*' => Http::response(['hasThumbnail' => true, 'thumbnailLink' => 'https://lh3.googleusercontent.com/html=s220']),
        'www.googleapis.com/drive/v3/files/evil?*' => Http::response(['hasThumbnail' => true, 'thumbnailLink' => 'https://evil.example.com/x=s220']),
        'lh3.googleusercontent.com/denied=*' => Http::response('no', 403),
        'lh3.googleusercontent.com/html=*' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $drive = app(GoogleDrive::class);

    expect(fn () => $drive->preview('none'))->toThrow(RuntimeException::class, 'Google Drive has no preview of none.')
        ->and(fn () => $drive->preview('denied'))->toThrow(RuntimeException::class, 'could not be fetched (403)')
        ->and(fn () => $drive->preview('html'))->toThrow(RuntimeException::class, 'could not be fetched (200)')
        ->and(fn () => $drive->preview('evil'))->toThrow(RuntimeException::class, 'points outside Google');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    expect(Storage::allFiles())->toBe([]);
});

test('list-claude-jobs hands out 25 waiting designs per call, oldest first, counts them per client and keeps a limited token to its client', function () {
    $folder = designsFolder($this->client);
    foreach (range(1, 30) as $i) {
        designRow($folder, ['source_id' => "w{$i}", 'name' => "Post {$i}.jpg", 'path' => 'Posts', 'modified_at' => now()->subDays(100 - $i)->setTime(12, 0)]);
    }
    designRow($folder, ['source_id' => 'oldest', 'name' => 'Opening 2024.ai', 'mime' => 'application/postscript', 'modified_at' => '2024-05-01 12:00']);
    designRow($folder, ['source_id' => 'done', 'name' => 'Described.jpg', 'modified_at' => '2020-01-01 12:00', 'text' => 'Done.', 'described_at' => now()]);
    designRow($folder, ['source_id' => 'nopreview', 'name' => 'Brochure.indd', 'modified_at' => '2020-01-01 12:00', 'error' => ClientDocument::NO_PREVIEW]);
    designRow($folder, ['source_id' => 'skipped', 'name' => 'Skip.jpg', 'kind' => 'ignored', 'modified_at' => '2020-01-01 12:00']);
    $other = Client::factory()->create(['name' => 'Kima', 'slug' => 'kima']);
    $otherFolder = designsFolder($other);
    designRow($otherFolder, ['source_id' => 'k1', 'name' => 'Kima summer.jpg', 'modified_at' => '2026-06-01 12:00']);
    designRow($otherFolder, ['source_id' => 'k2', 'name' => 'Kima winter.jpg', 'modified_at' => '2026-01-01 12:00']);

    [, $all] = ApiToken::issue('Worker', null, null);
    $jobs = json_decode(designsMcp($all, 'list-claude-jobs')['content'][0]['text'], true);

    expect($jobs['designs'])->toHaveCount(25)
        ->and($jobs['designs'][0])->toBe(['document' => ClientDocument::where('source_id', 'oldest')->value('id'), 'client' => 'fuse', 'name' => 'Opening 2024.ai', 'path' => '', 'modified' => '2024-05-01', 'how' => 'get-design, look at it, then save-design-description with document '.ClientDocument::where('source_id', 'oldest')->value('id')])
        ->and($jobs['designs'][1]['client'])->toBe('kima')->and($jobs['designs'][1]['name'])->toBe('Kima winter.jpg')
        ->and(collect($jobs['designs'])->pluck('name'))->not->toContain('Described.jpg')->not->toContain('Brochure.indd')->not->toContain('Skip.jpg')
        ->and($jobs['designs_waiting'])->toBe(['fuse' => 31, 'kima' => 2])
        ->and($jobs['captions'])->toBe([])->and($jobs['plans'])->toBe([]);
    $modified = collect($jobs['designs'])->pluck('modified')->all();
    expect($modified)->toBe(collect($modified)->sort()->values()->all()); // the archive in date order

    [, $kima] = ApiToken::issue('Kima only', $other->id, null);
    $jobs = json_decode(designsMcp($kima, 'list-claude-jobs')['content'][0]['text'], true);
    expect(collect($jobs['designs'])->pluck('name')->all())->toBe(['Kima winter.jpg', 'Kima summer.jpg'])->and($jobs['designs_waiting'])->toBe(['kima' => 2]);

    // an empty queue stays the three empty lists, so the worker stops without a reply
    ClientDocument::query()->update(['text' => 'x', 'described_at' => now()]);
    expect(designsMcp($all, 'list-claude-jobs')['content'][0]['text'])->toBe('{"captions":[],"plans":[],"designs":[]}');
});

test('get-design hands Claude the preview image and where the file sits, keeps nothing, and refuses another client\'s file or a document', function () {
    $folder = designsFolder($this->client);
    $design = designRow($folder, ['source_id' => 'f1', 'name' => 'Halloween.ai', 'mime' => 'application/postscript', 'path' => 'Instagram Posts/2. October 2026', 'modified_at' => '2026-10-20 12:00']);
    $document = $folder->documents()->make(['source_id' => 'doc', 'name' => 'Menu.docx', 'path' => '', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'kind' => 'reference', 'text' => 'Mojito 9', 'chars' => 8])->forceFill(['client_id' => $this->client->id]);
    $document->save();
    $other = Client::factory()->create();
    fakePreview('f1');

    SocialOpsServer::tool(GetDesign::class, ['document' => $design->id])->assertOk()->assertSee(base64_encode("\xFF\xD8\xFFJPEGBYTES"));
    [, $all] = ApiToken::issue('Worker', null, null);
    $result = designsMcp($all, 'get-design', ['document' => $design->id]);

    expect($result['isError'])->toBeFalse()
        ->and($result['content'][0])->toBe(['type' => 'image', 'data' => base64_encode("\xFF\xD8\xFFJPEGBYTES"), 'mimeType' => 'image/jpeg'])
        ->and($result['content'][1]['type'])->toBe('text');
    $text = json_decode($result['content'][1]['text'], true);
    expect($text)->toMatchArray(['document' => $design->id, 'client' => 'fuse', 'client_name' => 'Fuse', 'name' => 'Halloween.ai', 'path' => 'Instagram Posts/2. October 2026', 'modified' => '20-10-2026', 'modified_iso' => '2026-10-20', 'already_described' => false, 'write_in' => 'Greek', 'pillars' => ['place', 'food_drink', 'people', 'guests_ugc', 'offers']])
        ->and($text['instruction'])->toContain('the exact text on it as written')->toContain('Never invent what is not visible')->toContain('in Greek')
        ->and($text['then'])->toContain("save-design-description with document {$design->id}");
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'f1-thumb=s1024'));
    expect(Storage::allFiles())->toBe([])->and($design->fresh())->toMatchArray(['text' => null, 'error' => null]);

    [, $limited] = ApiToken::issue('Other only', $other->id, null);
    $refused = designsMcp($limited, 'get-design', ['document' => $design->id]);
    expect($refused['isError'])->toBeTrue()->and($refused['content'][0]['text'])->toContain("No design {$design->id} within this token's reach");
    expect(designsMcp($all, 'get-design', ['document' => $document->id])['isError'])->toBeTrue();
});

test('a preview Drive will not hand over takes the design out of the queue for a day, then the sync offers it again', function () {
    $folder = designsFolder($this->client);
    $design = designRow($folder, ['source_id' => 'f1', 'modified_at' => '2026-10-20 12:00']);
    designsKey();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files/f1?*' => Http::response(['hasThumbnail' => true, 'thumbnailLink' => 'https://lh3.googleusercontent.com/f1=s220']),
        'lh3.googleusercontent.com/*' => Http::response('', 500),
        'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => [['id' => 'f1', 'name' => 'f1.jpg', 'mimeType' => 'image/jpeg', 'modifiedTime' => '2026-10-20T09:00:00.000Z', 'hasThumbnail' => true]]]), // 12:00 in Athens: the file did not change
    ]);

    SocialOpsServer::tool(GetDesign::class, ['document' => $design->id])->assertHasErrors(['It leaves the queue for a day']);
    expect($design->fresh()->error)->toBe(ClientDocument::PREVIEW_FAILED);
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertSee('"designs":[]');

    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));
    expect($design->fresh()->error)->toBe(ClientDocument::PREVIEW_FAILED); // the same day: still out

    $this->travel(25)->hours();
    (new SyncClientDocuments($folder))->handle(app(GoogleDrive::class));
    expect($design->fresh())->toMatchArray(['error' => null, 'text' => null]);
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertSee('"document":'.$design->id);
});

test('save-design-description keeps one description per design with its fields, and the design leaves the queue', function () {
    $folder = designsFolder($this->client);
    $design = designRow($folder, ['source_id' => 'f1', 'name' => 'Halloween.ai', 'modified_at' => '2026-10-20 12:00']);
    $next = designRow($folder, ['source_id' => 'f2', 'modified_at' => '2026-10-21 12:00']);
    $other = Client::factory()->create();
    $foreign = designRow(designsFolder($other), ['source_id' => 'o1']);

    SocialOpsServer::tool(SaveDesignDescription::class, ['document' => $design->id, 'description' => str_repeat('x', 1490), 'topic' => 'Halloween party'])
        ->assertHasErrors(['The description and its fields come to 1513 characters; at most 1500 are kept.']);
    SocialOpsServer::tool(SaveDesignDescription::class, ['document' => $design->id, 'description' => str_repeat('x', 1501)])->assertHasErrors(['description']);
    SocialOpsServer::tool(SaveDesignDescription::class, ['document' => $design->id, 'description' => 'x', 'pillar' => 'nightlife'])->assertHasErrors(['The pillar must be one of the client\'s: place, food_drink, people, guests_ugc, offers.']);
    SocialOpsServer::tool(SaveDesignDescription::class, ['document' => $design->id, 'description' => 'x', 'post_type' => 'meme'])->assertHasErrors(['post type']);
    expect($design->fresh()->text)->toBeNull();

    SocialOpsServer::tool(SaveDesignDescription::class, [
        'document' => $design->id, 'description' => "Πόστερ για το Halloween πάρτι στο Fuse,\nκολοκύθες και κοκτέιλ σε σκούρο φόντο.",
        'text_on_image' => 'HALLOWEEN NIGHT 31.10 · DJ SET', 'topic' => 'Halloween πάρτι', 'pillar' => 'offers', 'post_type' => 'event', 'language' => 'en',
    ])->assertOk()->assertSee(['"saved":true', '"still_waiting":1']);

    $design->refresh();
    expect($design->text)->toBe("Πόστερ για το Halloween πάρτι στο Fuse,\nκολοκύθες και κοκτέιλ σε σκούρο φόντο.\nText on it: HALLOWEEN NIGHT 31.10 · DJ SET\nTopic: Halloween πάρτι\nType: event\nPillar: offers\nLanguage: en")
        ->and($design->chars)->toBe(mb_strlen($design->text))->and($design->described_at)->not->toBeNull()->and($design->error)->toBeNull();
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertSee('"document":'.$next->id)->assertDontSee('"document":'.$design->id.',');

    // described once: a second save is refused, as is a file of a client out of reach
    SocialOpsServer::tool(SaveDesignDescription::class, ['document' => $design->id, 'description' => 'Again'])->assertHasErrors(['is described already']);
    [, $limited] = ApiToken::issue('Other only', $other->id, null);
    $refused = designsMcp($limited, 'save-design-description', ['document' => $design->id, 'description' => 'Not mine']);
    expect($refused['isError'])->toBeTrue()->and($design->fresh()->text)->toStartWith('Πόστερ');
    expect(designsMcp($limited, 'save-design-description', ['document' => $foreign->id, 'description' => 'Mine'])['isError'])->toBeFalse();
    SocialOpsServer::tools()->assertRegistered([GetDesign::class, SaveDesignDescription::class]);
});

test('the planning brief carries the described designs newest first within the budget, and says how many still wait', function () {
    $folder = designsFolder($this->client, ['synced_at' => '2026-11-09 10:00:00']);
    designRow($folder, ['source_id' => 'a', 'name' => 'Summer menu.ai', 'modified_at' => '2026-06-01 12:00', 'text' => "Menu poster, cocktails.\nText on it: SUMMER MENU", 'described_at' => now()]);
    designRow($folder, ['source_id' => 'b', 'name' => 'Halloween.jpg', 'modified_at' => '2026-10-20 12:00', 'text' => 'Halloween party poster.', 'described_at' => now()]);
    designRow($folder, ['source_id' => 'c', 'name' => 'Waiting.jpg', 'modified_at' => '2026-10-21 12:00']);
    designRow($folder, ['source_id' => 'd', 'name' => 'Ignored.jpg', 'kind' => 'ignored', 'modified_at' => '2026-10-22 12:00', 'text' => 'IGNORED TEXT', 'described_at' => now()]);

    $reference = ClientReference::for($this->client);
    expect($reference['designs'])->toBe("- 20-10-2026 · Halloween.jpg: Halloween party poster.\n- 01-06-2026 · Summer menu.ai: Menu poster, cocktails. · Text on it: SUMMER MENU")
        ->and($reference['designs_waiting'])->toBe(1)->and($reference['designs_described'])->toBe(2)
        ->and(array_column($reference['files'], 'name'))->toBe([]); // the designs are not listed as files

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'fuse', 'posts' => 3])->assertOk()
        ->assertSee(["## Past designs from the client's folder (described from the images)", 'keep the recurring formats and seasons.\n- 20-10-2026 · Halloween.jpg: Halloween party poster.\n- 01-06-2026 · Summer menu.ai',
            '"note":"1 designs in the client\'s Drive folder are still waiting to be described (the designs of list-claude-jobs, 25 an hour); the past designs section holds the 2 described so far."'])
        ->assertDontSee('IGNORED TEXT')->assertDontSee('is linked but nothing has been read');

    // the budget: 16 000 characters in all, the older ones left out with a count
    foreach (range(1, 20) as $i) {
        designRow($folder, ['source_id' => "big{$i}", 'name' => "Big {$i}.jpg", 'modified_at' => now()->subDays($i), 'text' => str_repeat('Z', 1500), 'described_at' => now()]);
    }
    $designs = ClientReference::for($this->client)['designs'];
    expect(mb_strlen($designs))->toBeLessThanOrEqual(ClientReference::DESIGNS_TOTAL)
        ->and($designs)->toStartWith('- '.now()->subDay()->format('d-m-Y').' · Big 1.jpg: ZZZ')
        ->and($designs)->toEndWith('[… 12 older described designs left out to keep the brief short]')
        ->and($designs)->not->toContain('Halloween.jpg');
});

test('the Files tab lists the designs with their preview, description or state, and the preview passes through with the right headers', function () {
    $folder = designsFolder($this->client, ['synced_at' => '2026-11-09 10:00:00', 'file_count' => 0, 'design_count' => 4]);
    $done = designRow($folder, ['source_id' => 'f1', 'name' => 'Halloween.ai', 'mime' => 'application/postscript', 'path' => 'Posts', 'modified_at' => '2026-10-20 12:00', 'text' => "Halloween party poster.\nText on it: HALLOWEEN NIGHT", 'described_at' => now()]);
    $waiting = designRow($folder, ['source_id' => 'f2', 'name' => 'Reel.mov', 'mime' => 'video/quicktime', 'modified_at' => '2026-10-21 12:00']);
    $none = designRow($folder, ['source_id' => 'f3', 'name' => 'Brochure.indd', 'mime' => 'application/x-indesign', 'modified_at' => '2026-10-22 12:00', 'error' => ClientDocument::NO_PREVIEW]);
    designRow($folder, ['source_id' => 'f4', 'name' => 'Skip.jpg', 'kind' => 'ignored', 'kind_set_by_user' => true]);
    $text = $folder->documents()->make(['source_id' => 'doc', 'name' => 'Menu.docx', 'path' => '', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'kind' => 'reference', 'text' => 'Mojito 9', 'chars' => 8])->forceFill(['client_id' => $this->client->id]);
    $text->save();
    $other = Client::factory()->create();
    $foreign = designRow(designsFolder($other), ['source_id' => 'o1']);

    $html = $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->getContent();
    expect($html)->toContain('Last read 09-11-2026 10:00, 0 documents and 4 designs')
        ->toContain('Files<span class="ml-1 rounded-full bg-brand-100 px-1.5 text-xs text-brand-700">1</span>') // the tab counts the documents read, not the designs
        ->toContain('Designs</h3>')->toContain('1 of 2 described; Claude describes up to 25 an hour.')
        ->toContain('<img src="'.route('clients.files.preview', [$this->client, $done]).'" loading="lazy"')
        ->toContain('<summary class="cursor-pointer text-gray-900">Halloween party poster.</summary>')->toContain('Text on it: HALLOWEEN NIGHT')
        ->toContain('Posts · <span class="tabular-nums">20-10-2026</span>')
        ->toContain('Waiting for Claude')->toContain('Drive has no preview of this file')
        ->not->toContain(route('clients.files.preview', [$this->client, $none]))
        ->toContain('<option value="design" selected>Design</option>')->toContain('<option value="ignored" selected>Not used</option>')
        ->and(strpos($html, 'Brochure.indd'))->toBeLessThan(strpos($html, 'Halloween.ai')) // newest Drive date first
        ->and(strpos($html, 'Menu.docx'))->toBeLessThan(strpos($html, 'Designs</h3>'));
    expect(substr_count($html, '<option value="previous_posts"'))->toBe(1); // only the document offers the document kinds

    // the team leaves a design out and takes it back; a document kind is refused for a design
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $waiting]), ['kind' => 'ignored'])->assertRedirect(route('clients.files', $this->client));
    expect($waiting->fresh())->toMatchArray(['kind' => 'ignored', 'kind_set_by_user' => true]);
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $waiting]), ['kind' => 'reference'])->assertSessionHasErrors('kind');
    $this->actingAs($this->user)->patch(route('clients.files.kind', [$this->client, $waiting]), ['kind' => 'design'])->assertSessionHasNoErrors();
    expect($waiting->fresh()->kind)->toBe('design');

    // the preview passes through from Drive, private and never sniffed, and nothing lands on the disk
    fakePreview('f1', 'PNGBYTES');
    $this->actingAs($this->user)->get(route('clients.files.preview', [$this->client, $done]))->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=3600, private')
        ->assertContent('PNGBYTES');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'f1-thumb=s400'));
    expect(Storage::allFiles())->toBe([]);

    // a text document, a design Drive has no preview of, a file Drive answers nothing for and another client's file: 404
    $this->actingAs($this->user)->get(route('clients.files.preview', [$this->client, $text]))->assertNotFound();
    $this->actingAs($this->user)->get(route('clients.files.preview', [$this->client, $none]))->assertNotFound();
    $this->actingAs($this->user)->get(route('clients.files.preview', [$this->client, $waiting]))->assertNotFound(); // f2: not in the fake, Drive fails
    $this->actingAs($this->user)->get(route('clients.files.preview', [$this->client, $foreign]))->assertNotFound();

    // a login limited to another client cannot reach this client's previews
    $limited = User::factory()->create(['client_ids' => [$other->id]]);
    $this->actingAs($limited)->get(route('clients.files.preview', [$this->client, $done]))->assertForbidden();

    // the client page's MCP box counts the designs apart from the files
    config()->set('features.claude_api', false);
    $this->actingAs($this->user)->get(route('clients.edit', $this->client))->assertOk()
        ->assertSee('Claude also reads 1 files from the client&#039;s Drive folder (0 with previous posts).', false)->assertSee('And 1 past designs it described, 1 still waiting.');

    // in Greek
    $this->actingAs(User::factory()->create(['locale' => 'el']))->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Σχέδια')->assertSee('1 από 2 περιγράφηκαν')->assertSee('Περιμένει τον Claude')->assertSee('Το Drive δεν έχει προεπισκόπηση αυτού του αρχείου');
});

test('a long list of designs shows the newest 60 with a link to all of them', function () {
    $folder = designsFolder($this->client);
    foreach (range(1, 65) as $i) {
        designRow($folder, ['source_id' => "d{$i}", 'name' => sprintf('Design %02d.jpg', $i), 'modified_at' => now()->subDays($i)]);
    }

    $html = $this->actingAs($this->user)->get(route('clients.files', $this->client))->assertOk()->getContent();
    expect($html)->toContain('Design 01.jpg')->toContain('Design 60.jpg')->not->toContain('Design 61.jpg')->toContain('Show all 65 designs');
    $this->actingAs($this->user)->get(route('clients.files', [$this->client, 'all' => 1]))->assertOk()->assertSee('Design 65.jpg')->assertDontSee('Show all 65 designs');
});
