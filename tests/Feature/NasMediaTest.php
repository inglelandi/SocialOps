<?php

use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\AttachNasFile;
use App\Mcp\Tools\GetCaptionBrief;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListClaudeJobs;
use App\Media\NasConnector;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Posts\PostState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// D30 (Panos, 2026-11-19: "the scheduled worker should be able to add images or videos based on the post on the posts (not
// create new) and if the inglelandi staff want to change the images it should be able to"): Claude picks a photo or video in
// the client's NAS folder, the connector makes a one-time link, attach-nas-file copies the file into the client's Files and
// puts it on the post; the team changes it in the post form like any file.

const NAS = 'https://nas-mcp.example.com';
const NAS_KEY = 'a-download-key-of-at-least-32-characters-long';
const NAS_LINK = NAS.'/download/tok_0123456789abcdefghijklmnop';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-11-19 10:00:00');
    config(['services.nas.url' => NAS, 'services.nas.download_key' => NAS_KEY]);
    $this->client = Client::factory()->create(['name' => 'Dokos', 'code' => 'DOKOS', 'slug' => 'dokos']);
    $this->client->update(['profile' => ['nas_folder' => 'Dokos Home'] + $this->client->profile]);
    $this->pillar = array_key_first($this->client->profile['pillars']);
    $this->post = Post::factory()->create(['client_id' => $this->client->id, 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $this->pillar, 'state' => PostState::Draft]);
});

/** A small real JPEG, as the connector would hand it over. */
function nasJpeg(): string
{
    $image = imagecreatetruecolor(40, 30);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 60, 200));
    ob_start();
    imagejpeg($image);

    return ob_get_clean();
}

function attach(array $input)
{
    return SocialOpsServer::tool(AttachNasFile::class, $input);
}

test('Claude puts a NAS photo on a Draft: copied once into the client\'s files, named by the rule, marked Office NAS, after the files already there', function () {
    Http::fake([NAS.'/download/*' => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    attach(['post' => $this->post->id, 'url' => NAS_LINK, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'])->assertOk()
        ->assertSee(['"copied":true', '"on_posts":1', 'The file is on the post']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === NAS_LINK && $request->header(NasConnector::HEADER) === [NAS_KEY]);

    $asset = $this->client->assets()->sole();
    expect($asset)->toMatchArray(['name' => 'DOKOS_20261119_ANY_IMG_'.strtoupper($this->pillar).'_EL-EN_v1.jpg', 'original_name' => 'IMG_0001.jpg', 'kind' => 'image', 'mime' => 'image/jpeg',
        'uploaded_via' => 'nas', 'source_id' => 'nas:Dokos Home/Photos/IMG_0001.jpg', 'note' => 'Office NAS: Dokos Home/Photos/IMG_0001.jpg', 'pillar' => $this->pillar])
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue()
        ->and($this->post->fresh()->assets->pluck('id')->all())->toBe([$asset->id]);

    // a second file for a carousel goes after the first; the same NAS file on another post is not copied again and needs no url
    Http::fake([NAS.'/download/*' => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    attach(['post' => $this->post->id, 'url' => NAS.'/download/tok_second_link_000000000000', 'path' => 'Dokos Home/Photos/IMG_0002.jpg'])->assertOk()->assertSee('"copied":true');
    $other = Post::factory()->create(['client_id' => $this->client->id, 'platforms' => ['instagram'], 'format' => 'feed', 'state' => PostState::Draft]);
    attach(['post' => $other->id, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'])->assertOk()->assertSee(['"copied":false', '"on_posts":2', 'copied from the NAS before']);
    attach(['post' => $other->id, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'])->assertOk()->assertSee('"on_posts":2'); // twice on the same post changes nothing
    Http::assertSentCount(1);
    expect($this->client->assets()->count())->toBe(2)
        ->and($this->post->fresh()->assets->pluck('original_name')->all())->toBe(['IMG_0001.jpg', 'IMG_0002.jpg'])
        ->and($this->post->fresh()->assets->pluck('pivot.position')->all())->toBe([0, 1])
        ->and($other->fresh()->assets->pluck('id')->all())->toBe([$asset->id]);

    // a path too long for the source column is kept whole in the note and hashed in the source, and is still copied once
    $deep = 'Dokos Home/Instagram Posts/2. October 2026/Posts/Next posts/Carousel for the winter opening hours/IMG_0003_final_version_2.jpg';
    Http::fake([NAS.'/download/*' => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    attach(['post' => $other->id, 'url' => NAS.'/download/tok_third_link_0000000000000', 'path' => $deep])->assertOk()->assertSee('"copied":true');
    attach(['post' => $this->post->id, 'path' => $deep])->assertOk()->assertSee('"copied":false');
    Http::assertSentCount(1);
    expect($this->client->assets()->where('source_id', 'nas:'.sha1($deep))->sole()->note)->toBe('Office NAS: '.$deep)
        ->and(strlen(AttachNasFile::sourceId($deep)))->toBeLessThanOrEqual(100);
    $this->post->assets()->detach($this->client->assets()->where('source_id', 'nas:'.sha1($deep))->sole()->id);

    // the team sees the source on the Files page and can change the post's files in the form as before
    $this->actingAs(User::factory()->create());
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('Office NAS')->assertSee('3 copied so far');
    $this->patch(route('posts.update', $this->post), ['platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $this->pillar, 'brief' => $this->post->brief, 'assets' => [$this->post->assets[1]->id]])->assertSessionHasNoErrors();
    expect($this->post->fresh()->assets->pluck('original_name')->all())->toBe(['IMG_0002.jpg']);
});

test('what attach-nas-file refuses, before it fetches anything', function () {
    Http::fake();
    $file = ['url' => NAS_LINK, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'];

    attach(['post' => $this->post->id, 'url' => 'https://evil.example.com/download/tok_0123456789abcdefghijklmnop', 'path' => $file['path']])
        ->assertHasErrors(['Only links the connector made are fetched ('.NAS.'/download/…)']);
    attach(['post' => $this->post->id, 'url' => NAS.'/download/tok_0123456789abcdefghijklmnop/../../mcp', 'path' => $file['path']])
        ->assertHasErrors(['Only links the connector made are fetched']);
    attach(['post' => $this->post->id, 'path' => $file['path']])->assertHasErrors(['This file is not in Social Ops yet: give the url get_download_link made for it.']);
    attach(['post' => $this->post->id, 'path' => 'Almyra Beach/Photos/IMG_0001.jpg'] + $file)
        ->assertHasErrors(['"Almyra Beach/Photos/IMG_0001.jpg" is not inside Dokos\'s NAS folder "Dokos Home"']);
    attach(['post' => $this->post->id, 'path' => 'Dokos Home'] + $file)->assertHasErrors(['is not inside Dokos\'s NAS folder']);
    attach(['post' => $this->post->id, 'path' => 'Dokos Homeless/IMG_0001.jpg'] + $file)->assertHasErrors(['is not inside Dokos\'s NAS folder']);

    $reel = Post::factory()->create(['client_id' => $this->client->id, 'platforms' => ['instagram'], 'format' => 'reel', 'state' => PostState::Draft]);
    attach(['post' => $reel->id] + $file)->assertHasErrors(["Post {$reel->id} is a reel: it takes a video (MP4 or MOV), not a photo."]);

    $this->post->update(['state' => PostState::InReview]);
    attach(['post' => $this->post->id] + $file)->assertHasErrors(["Post {$this->post->id} is with the client for review: the team changes its files in the app."]);
    $this->post->update(['state' => PostState::Approved]);
    attach(['post' => $this->post->id] + $file)->assertHasErrors(["Post {$this->post->id} is Approved: the team changes its files in the app."]);
    $this->post->update(['state' => PostState::Draft]);

    $team = Asset::factory()->create(['client_id' => $this->client->id, 'uploaded_via' => 'staff', 'name' => 'DOKOS_20261101_ANY_IMG_MISC_EL-EN_v1.jpg']);
    $this->post->assets()->attach($team->id, ['position' => 0]);
    attach(['post' => $this->post->id] + $file)->assertHasErrors(["Post {$this->post->id} already has files the team chose (DOKOS_20261101_ANY_IMG_MISC_EL-EN_v1.jpg): leave them, add nothing."]);
    $this->post->assets()->detach();

    $other = Client::factory()->create();
    $foreign = Post::factory()->create(['client_id' => $other->id, 'platforms' => ['instagram'], 'format' => 'feed', 'state' => PostState::Draft]);
    attach(['post' => $foreign->id] + $file)->assertHasErrors(['has no folder on the office NAS in its profile (nas_folder)']);

    config(['services.nas.download_key' => null]);
    attach(['post' => $this->post->id] + $file)->assertHasErrors(['Social Ops cannot copy files from the NAS yet: NAS_CONNECTOR_URL']);
    config(['services.nas.download_key' => 'short', 'services.nas.url' => NAS]);
    attach(['post' => $this->post->id] + $file)->assertHasErrors(['Social Ops cannot copy files from the NAS yet']);

    Http::assertNothingSent();
    expect($this->client->assets()->where('uploaded_via', 'nas')->count())->toBe(0)->and($this->post->fresh()->assets)->toHaveCount(0);
});

test('the tool tells Claude its fields, and a failure after the checks is said in words instead of an internal server error', function () {
    $schema = (new AttachNasFile)->toArray()['inputSchema'];
    expect(array_keys($schema['properties']))->toBe(['post', 'path', 'url'])->and($schema['required'])->toBe(['post', 'path']);

    // the client's files folder cannot be written (here: the disk's root is a file, so the folder cannot be made)
    $blocked = sys_get_temp_dir().'/socialops-blocked-'.uniqid();
    file_put_contents($blocked, 'not a folder');
    config(['filesystems.disks.blocked' => ['driver' => 'local', 'root' => $blocked], 'filesystems.default' => 'blocked']);
    Http::fake([NAS.'/download/*' => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    attach(['post' => $this->post->id, 'url' => NAS_LINK, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'])
        ->assertHasErrors(['Social Ops could not file the download (UnableToCreateDirectory:', 'cannot be written by the site\'s user', 'plesk repair fs', 'Leave the post without files and tell the team.']);
    @unlink($blocked);
    expect($this->client->assets()->count())->toBe(0)->and($this->post->fresh()->assets)->toHaveCount(0)
        ->and(glob(sys_get_temp_dir().'/nas*') ?: [])->toBe([]);
});

test('what the connector answers is read back in words Claude can act on, and nothing is filed', function () {
    // one fake per case: Laravel's HTTP fakes stack and the first match answers, so each case gets its own link
    $link = fn (string $case) => NAS.'/download/tok_'.$case.'_0123456789abcdef';
    $file = fn (string $case) => ['post' => $this->post->id, 'url' => $link($case), 'path' => 'Dokos Home/Photos/IMG_0001.jpg'];
    Http::fake([
        $link('forbidden') => Http::response('forbidden', 403),
        $link('gone') => Http::response('gone', 410),
        $link('failed') => Http::response('', 500),
        $link('big') => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (300 * 1024 * 1024)]),
        $link('html') => Http::response('<html>not a photo</html>', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    attach($file('forbidden'))->assertHasErrors(['The connector refused the download key: NAS_DOWNLOAD_KEY in Social Ops\' Settings and secrets/download_key on the NAS must be the same']);
    attach($file('gone'))->assertHasErrors(['The link expired or was used already: ask get_download_link for a new one']);
    attach($file('failed'))->assertHasErrors(['The connector answered 500 for this link.']);
    attach($file('big'))->assertHasErrors(['Too big: Social Ops takes files up to 200 MB.']);
    attach($file('html'))->assertHasErrors(['"IMG_0001.jpg" is not a photo or video Social Ops takes (text/html)']);

    Http::assertSentCount(5);
    expect($this->client->assets()->count())->toBe(0)->and($this->post->fresh()->assets)->toHaveCount(0)
        ->and(glob(sys_get_temp_dir().'/nas*') ?: [])->toBe([]); // no temp file left behind
});

test('the briefs, the queued jobs and the pages say how the posts get their files, and when Social Ops cannot copy them', function () {
    Http::fake([NAS.'/download/*' => Http::response(nasJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    attach(['post' => $this->post->id, 'url' => NAS_LINK, 'path' => 'Dokos Home/Photos/IMG_0001.jpg'])->assertOk();

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['"copies_files":true', '"files_copied":["Dokos Home/Photos/IMG_0001.jpg"]', 'Files for the posts: after save-plan, give every new post that has no files one photo or video from this folder', 'get_download_link, then attach-nas-file', 'a Reel takes a video', 'never a file of another client']);
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $this->post->id])->assertOk()
        ->assertSee(['"copies_files":true', 'Files for the post: if it has no files, give it one photo or video from this folder', 'attach-nas-file']);
    $this->client->reports()->create(['kind' => 'claude_job', 'title' => 'Plan 4 posts', 'body' => '', 'meta' => ['posts' => 4, 'from' => '2027-05-01', 'days' => 30]]);
    $this->post->update(['settings' => ['ask_claude' => true]]);
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()
        ->assertSee(['give every new post a photo or video from that folder as the brief\'s nas_folder.how says (view_image, get_download_link, attach-nas-file)', 'when the brief names a nas_folder and the post has no files, give it a photo or video from that folder']);

    $this->actingAs(User::factory()->create());
    config()->set('features.claude_api', false);
    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('It puts photos and videos from it on the posts it plans.');
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('Claude puts photos and videos from this folder on the posts it plans or writes that have none yet (1 copied so far)');

    config(['services.nas.url' => null, 'services.nas.download_key' => null]);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['"copies_files":false', 'Files for the posts: do not call attach-nas-file, Social Ops cannot copy files from the NAS yet'])->assertDontSee('files_copied');
    $this->get(route('clients.planner', $this->client))->assertOk()->assertDontSee('It puts photos and videos from it on the posts it plans.');
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('set NAS_CONNECTOR_URL and NAS_DOWNLOAD_KEY (the connector\'s download key) in Settings');
});
