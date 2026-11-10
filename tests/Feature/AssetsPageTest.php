<?php

use App\Http\Requests\InboxUploadRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// The assets page of 2026-11-06: the team's own upload first, the other ways folded under it, then the files with filters.
beforeEach(function () {
    Storage::fake(config('filesystems.default'));
    $this->travelTo('2026-11-06 10:00:00');
    $this->client = Client::factory()->create(['slug' => 'almyra', 'code' => 'ALMYRA', 'name' => 'Almyra Beach Lounge']);
    $this->staff = User::factory()->create();
});

test('staff upload several photos and videos at once: agency names, the batch tags, uploaded_via staff', function () {
    $this->actingAs($this->staff)->post(route('assets.store', $this->client), [
        'files' => [UploadedFile::fake()->image('IMG_0001.JPG', 1200, 800), UploadedFile::fake()->image('IMG_0002.png'), UploadedFile::fake()->create('clip.mov', 2048, 'video/quicktime')],
        'pillar' => 'food_drink',
        'season' => 'autumn menu',
        'people_consent' => '1',
        'note' => 'Shot by Maria',
    ])->assertRedirect(route('clients.assets', $this->client))->assertSessionHasNoErrors()->assertSessionHas('status', '3 files added');

    $assets = Asset::orderBy('id')->get();
    expect($assets->pluck('name')->all())->toBe([
        'ALMYRA_20261106_ANY_IMG_FOODDRINK_EL-EN_v1.jpg',
        'ALMYRA_20261106_ANY_IMG_FOODDRINK_EL-EN_v2.png',
        'ALMYRA_20261106_ANY_VID_FOODDRINK_EL-EN_v1.mov',
    ]);
    foreach ($assets as $asset) {
        expect($asset)->toMatchArray(['client_id' => $this->client->id, 'uploaded_via' => 'staff', 'pillar' => 'food_drink', 'season' => 'autumn menu', 'people_consent' => true, 'ugc_permission' => false, 'note' => 'Shot by Maria']);
        Storage::disk($asset->disk)->assertExists("clients/almyra/inbox/{$asset->name}");
    }
    expect($assets->pluck('original_name')->all())->toBe(['IMG_0001.JPG', 'IMG_0002.png', 'clip.mov'])
        ->and($assets->pluck('kind')->all())->toBe(['image', 'image', 'video'])
        ->and(array_keys(Asset::sources()))->toContain('staff');

    $this->get(route('clients.assets', $this->client))->assertOk()
        ->assertSee('3 files added')->assertSee('ALMYRA_20261106_ANY_VID_FOODDRINK_EL-EN_v1.mov')->assertSee('Team upload')->assertSee('autumn menu');
});

test('a refused file never stops the others, and each file says why', function () {
    $batch = fn () => [
        UploadedFile::fake()->image('pool.jpg'),
        UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        UploadedFile::fake()->create('huge.mp4', InboxUploadRequest::MAX_KB + 1, 'video/mp4'),
        UploadedFile::fake()->create('sunset.mp4', 300, 'video/mp4'),
    ];

    // the page's uploader asks for JSON
    $json = $this->actingAs($this->staff)->post(route('assets.store', $this->client), ['files' => $batch()], ['Accept' => 'application/json'])
        ->assertCreated()->json();
    expect(array_column($json['uploaded'], 'original_name'))->toBe(['pool.jpg', 'sunset.mp4'])
        ->and(array_column($json['uploaded'], 'name'))->toBe(['ALMYRA_20261106_ANY_IMG_MISC_EL-EN_v1.jpg', 'ALMYRA_20261106_ANY_VID_MISC_EL-EN_v1.mp4'])
        ->and($json['failed'])->toBe([
            ['name' => 'virus.exe', 'error' => 'Only JPG, PNG, WEBP, HEIC photos and MP4 or MOV videos are accepted.'],
            ['name' => 'huge.mp4', 'error' => 'Too big: 200 MB at most.'],
        ])
        ->and(Asset::count())->toBe(2);

    // one file per request: a refused one answers 422 and stores nothing
    $this->post(route('assets.store', $this->client), ['files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('failed.0.name', 'notes.pdf')->assertJsonPath('uploaded', []);
    expect(Asset::count())->toBe(2);

    // a plain form post files the good ones and lists the refused ones above the drop zone
    $this->post(route('assets.store', $this->client), ['files' => $batch()])
        ->assertRedirect(route('clients.assets', $this->client))->assertSessionHas('status', '2 files added');
    expect(session('errors')->getBag('upload')->all())->toBe([
        'virus.exe: Only JPG, PNG, WEBP, HEIC photos and MP4 or MOV videos are accepted.',
        'huge.mp4: Too big: 200 MB at most.',
    ])->and(Asset::count())->toBe(4);
    $this->followingRedirects()->post(route('assets.store', $this->client), ['files' => $batch()])->assertOk()
        ->assertSee('2 files added')->assertSee('virus.exe: Only JPG')->assertSee('huge.mp4: Too big');
});

test('the batch fields are checked: a known pillar, at least one file', function () {
    $this->actingAs($this->staff)->post(route('assets.store', $this->client), ['files' => [UploadedFile::fake()->image('a.jpg')], 'pillar' => 'nope'])
        ->assertSessionHasErrorsIn('upload', ['pillar']);
    $this->post(route('assets.store', $this->client), [])->assertSessionHasErrorsIn('upload', ['files' => 'Choose at least one photo or video.']);
    $this->post(route('assets.store', $this->client), ['files' => [UploadedFile::fake()->image('a.jpg')], 'pillar' => 'nope'], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('pillar');

    expect(Asset::count())->toBe(0);
});

test('only staff upload, and a login limited to other clients cannot upload, open or see the files here', function () {
    $other = Client::factory()->create(['slug' => 'blue-villa', 'code' => 'BLUE']);
    $mine = Asset::factory()->create(['client_id' => $this->client->id]);
    $file = fn () => [UploadedFile::fake()->image('a.jpg')];

    $this->post(route('assets.store', $this->client), ['files' => $file()])->assertRedirect('/login');

    $limited = User::factory()->create(['client_ids' => [$other->id]]);
    $this->actingAs($limited)->post(route('assets.store', $this->client), ['files' => $file()])->assertForbidden();
    $this->actingAs($limited)->post(route('assets.store', $this->client), ['files' => $file()], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($limited)->get(route('assets.view', $mine))->assertForbidden();
    $this->actingAs($limited)->get(route('clients.assets', $this->client))->assertForbidden();
    expect(Asset::count())->toBe(1);

    $this->actingAs($limited)->post(route('assets.store', $other), ['files' => $file()])->assertRedirect(route('clients.assets', $other));
    expect(Asset::where('client_id', $other->id)->sole()->name)->toBe('BLUE_20261106_ANY_IMG_MISC_EL-EN_v1.jpg');
});

test('a file opens in the browser for the preview', function () {
    $this->actingAs($this->staff)->post(route('assets.store', $this->client), ['files' => [UploadedFile::fake()->image('pool.jpg', 40, 30)]]);
    $asset = Asset::sole();

    $this->get(route('assets.view', $asset))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
    $html = $this->get(route('clients.assets', $this->client))->assertSee('Open in a new tab')->getContent();
    expect(stripslashes(stripslashes($html)))->toContain(route('assets.view', $asset)); // inside the @js() of the thumbnail's click
});

test('the files filter by pillar, kind, use in a post, and a search over the names and the tags', function () {
    $make = fn (array $attrs) => Asset::factory()->create(['client_id' => $this->client->id] + $attrs);
    $sunset = $make(['name' => 'ALMYRA_SUNSET.jpg', 'original_name' => 'IMG_4101.JPG', 'pillar' => 'place', 'season' => 'summer 2027']);
    $make(['name' => 'ALMYRA_OCTOPUS.jpg', 'original_name' => 'dish.jpg', 'pillar' => 'food_drink', 'note' => 'Grilled octopus, new menu']);
    $make(['name' => 'ALMYRA_CLIP.mp4', 'original_name' => 'venue.mp4', 'kind' => 'video', 'mime' => 'video/mp4', 'pillar' => 'place']);
    $binnedUse = $make(['name' => 'ALMYRA_BINNED.jpg', 'original_name' => 'b.jpg']);
    Post::factory()->for($this->client)->create()->assets()->attach($sunset->id, ['position' => 0]);
    $bin = Post::factory()->for($this->client)->create();
    $bin->assets()->attach($binnedUse->id, ['position' => 0]);
    $bin->moveToBin(); // a post in the recycle bin still uses its file

    $shows = function (array $query, array $expected) {
        $html = $this->actingAs($this->staff)->get(route('clients.assets', [$this->client] + $query))->assertOk()->getContent();
        $shown = array_values(array_filter(['ALMYRA_SUNSET.jpg', 'ALMYRA_OCTOPUS.jpg', 'ALMYRA_CLIP.mp4', 'ALMYRA_BINNED.jpg'], fn ($name) => str_contains($html, $name)));
        expect($shown)->toBe($expected, json_encode($query));

        return $html;
    };

    $html = $shows([], ['ALMYRA_SUNSET.jpg', 'ALMYRA_OCTOPUS.jpg', 'ALMYRA_CLIP.mp4', 'ALMYRA_BINNED.jpg']);
    expect($html)->toContain('Photos and videos (4)')->not->toContain('Clear filters');
    $shows(['used' => 'yes'], ['ALMYRA_SUNSET.jpg', 'ALMYRA_BINNED.jpg']);
    $shows(['used' => 'no'], ['ALMYRA_OCTOPUS.jpg', 'ALMYRA_CLIP.mp4']);
    $shows(['kind' => 'video', 'used' => 'no'], ['ALMYRA_CLIP.mp4']);
    $shows(['q' => 'octopus'], ['ALMYRA_OCTOPUS.jpg']); // the name and the note
    $shows(['q' => 'IMG_4101'], ['ALMYRA_SUNSET.jpg']); // the file's own name
    $shows(['q' => 'summer'], ['ALMYRA_SUNSET.jpg']); // the season or event
    $shows(['q' => 'food drink'], ['ALMYRA_OCTOPUS.jpg']); // the pillar as the page shows it
    $shows(['pillar' => 'place', 'q' => 'clip'], ['ALMYRA_CLIP.mp4']);
    $html = $shows(['q' => 'summer', 'used' => 'yes'], ['ALMYRA_SUNSET.jpg']);
    expect($html)->toContain('value="summer"')->toContain('<option value="yes" selected')->toContain('Clear filters')->toContain('Photos and videos (1)');

    $html = $shows(['q' => 'nothing like this'], []);
    expect($html)->toContain('Nothing matches these filters.')->toContain('href="'.route('clients.assets', $this->client).'"');
});

test('delete asks first and names the posts that would lose the file, one in the recycle bin too, and keeps the filters', function () {
    $used = Asset::factory()->create(['client_id' => $this->client->id, 'name' => 'ALMYRA_USED.jpg', 'path' => 'clients/almyra/inbox/ALMYRA_USED.jpg']);
    $free = Asset::factory()->create(['client_id' => $this->client->id, 'name' => 'ALMYRA_FREE.jpg', 'path' => 'clients/almyra/inbox/ALMYRA_FREE.jpg']);
    Storage::disk('local')->put($free->path, 'jpeg');
    $post = Post::factory()->for($this->client)->create(['name' => 'ALMYRA_20261112_IG_FEED_PLACE_EL-EN_v1']);
    $post->assets()->attach($used->id, ['position' => 0]);
    $binned = Post::factory()->for($this->client)->create(['name' => 'ALMYRA_20261120_IG_FEED_FOOD_EL-EN_v1']);
    $binned->assets()->attach($used->id, ['position' => 0]);
    $binned->moveToBin();

    $html = $this->actingAs($this->staff)->get(route('clients.assets', $this->client))->assertOk()->getContent();
    expect($html)
        ->toContain('data-confirm="Delete this file? It is used in ALMYRA_20261112_IG_FEED_PLACE_EL-EN_v1, ALMYRA_20261120_IG_FEED_FOOD_EL-EN_v1 (recycle bin) and will be removed from it."')
        ->toContain('data-confirm="Delete this file?"')
        ->toContain('onsubmit="return confirm(this.dataset.confirm)"');

    // a delete made while the grid is filtered comes back to the same filters
    expect($this->get(route('clients.assets', [$this->client, 'q' => 'free', 'kind' => 'image']))->getContent())
        ->toContain('<input type="hidden" name="q" value="free">')->toContain('<input type="hidden" name="kind" value="image">');
    $this->delete(route('assets.destroy', $free), ['q' => 'free', 'kind' => 'image'])
        ->assertRedirect(route('clients.assets', ['client' => $this->client->id, 'q' => 'free', 'kind' => 'image']));
    Storage::disk('local')->assertMissing($free->path);

    $this->delete(route('assets.destroy', $used))->assertRedirect(route('clients.assets', $this->client));
    expect(Asset::count())->toBe(0)->and($post->assets()->count())->toBe(0);
});

test('the Files page lists its sections in a side list: the photos and videos with the upload first, the other ways in, the Reel maker, then what Claude reads', function () {
    config(['services.ffmpeg.path' => PHP_BINARY]); // any executable shows the Reel form (the page never runs it), so CI without ffmpeg checks it too; ReelTest covers "ffmpeg missing"
    Asset::factory()->create(['client_id' => $this->client->id, 'kind' => 'video', 'mime' => 'video/mp4', 'name' => 'ALMYRA_CLIP.mp4', 'original_name' => 'IMG_0002.mp4']);
    Asset::factory()->create(['client_id' => $this->client->id, 'kind' => 'video', 'mime' => 'video/mp4', 'name' => 'ALMYRA_CLIP2.mp4', 'original_name' => 'IMG_0001.mp4']);

    $html = $this->actingAs($this->staff)->get(route('clients.assets', $this->client))->assertOk()
        ->assertSeeInOrder(['data-tab="media"', 'Photos and videos', 'data-tab="sources"', 'Other ways to add files', 'data-tab="reel"', 'Make a Reel', 'What Claude reads', 'data-tab="slideshow"', 'data-tab="folder"', 'data-tab="designs"', 'data-tab="feed"',
            'Add files', 'Drop photos and videos here', 'For every file in this upload', 'Photos and videos (2)', 'Search by name or tag',
            'Upload link for the client', '/inbox/almyra?expires=', 'Copy', 'Google Drive', 'Dropbox', 'Canva', 'Make a Reel', 'IMG_0001.mp4', 'IMG_0002.mp4',
            'Posts slideshow', 'Google Drive folder', 'Designs', 'Product feed'], false)
        ->getContent();
    expect($html)->toContain('action="'.route('assets.store', $this->client).'"')->toContain('enctype="multipart/form-data"')->toContain('name="files[]"')
        ->toContain('x-show="tab === \'sources\'"')->toContain('x-show="tab === \'reel\'"');

    // the section the page opens on (filesPage's `open`): /assets the photos, /files the one opened last (null), else what the last action was about
    $opens = fn ($response) => preg_match('/\\\\u0022open\\\\u0022:(?:\\\\u0022)?(\w+)/', $response->assertOk()->getContent(), $m) ? $m[1] : 'missing';
    expect($opens($this->get(route('clients.assets', $this->client))))->toBe('media')
        ->and($opens($this->get(route('clients.files', $this->client))))->toBe('null')
        ->and($opens($this->get(route('clients.files', [$this->client, 'q' => 'sunset']))))->toBe('media');
    // a Canva search, a Dropbox just connected (its folder still to name) or an error from one of the other ways opens them
    expect($opens($this->get(route('clients.assets', [$this->client, 'canva' => '']))))->toBe('sources')
        ->and($opens($this->withSession(['status' => 'dropbox-connected'])->get(route('clients.assets', $this->client))))->toBe('sources');
    $this->client->accounts()->create(['platform' => 'dropbox', 'external_id' => 'dbid:abc', 'name' => 'Panos', 'access_token' => 'at', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour()]);
    Http::fake(['api.dropboxapi.com/2/files/get_metadata' => Http::response(['error_summary' => 'path/not_found/..', 'error' => ['.tag' => 'path']], 409)]);
    $response = $this->followingRedirects()->post(route('dropbox.watch', $this->client), ['folder' => '/Clients/Nope'])->assertOk()
        ->assertSee('Dropbox: no such folder.')->assertSee('value="/Clients/Nope"', false);
    expect($opens($response))->toBe('sources')
        ->and(substr_count($response->getContent(), 'value="/Clients/Nope"'))->toBe(1); // only in the Dropbox box, not in the reference folder's
    expect($opens($this->withSession(['tab' => 'feed'])->get(route('clients.assets', $this->client))))->toBe('feed'); // an action's redirect names its section
});
