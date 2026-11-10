<?php

use App\Jobs\AssembleReel;
use App\Media\ReelAssembler;
use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA']);
});

/** A test-pattern clip of $seconds at $size, filed in the client's inbox on the fake disk. */
function clip(Client $client, string $name, int $seconds, string $size): Asset
{
    $tmp = tempnam(sys_get_temp_dir(), 'clip').'.mp4';
    (new Process([ReelAssembler::binary(), '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', "testsrc=size={$size}:rate=30", '-t', (string) $seconds, '-pix_fmt', 'yuv420p', $tmp]))->mustRun();
    Storage::disk('local')->put("clients/almyra/inbox/{$name}", file_get_contents($tmp));
    @unlink($tmp);

    return Asset::factory()->create(['client_id' => $client->id, 'name' => $name, 'original_name' => $name, 'path' => "clients/almyra/inbox/{$name}", 'mime' => 'video/mp4', 'kind' => 'video']);
}

test('the Files page offers a Reel once the client has two clips, and says so when ffmpeg is missing', function () {
    $video = fn () => Asset::factory()->create(['client_id' => $this->client->id, 'kind' => 'video', 'mime' => 'video/mp4', 'name' => 'ALMYRA_20261104_ANY_VID_MISC_EL-EN_v'.Asset::count().'.mp4']);
    $this->actingAs($this->user)->get(route('clients.assets', $this->client))->assertOk()->assertSee('Make a Reel')->assertSee('Upload at least two videos to make a Reel.')->assertDontSee('In the Reel');
    $video();
    $this->actingAs($this->user)->get(route('clients.assets', $this->client))->assertOk()->assertSee('Upload at least two videos to make a Reel.');
    $video();
    $this->actingAs($this->user)->get(route('clients.assets', $this->client))->assertOk()->assertSee('Make a Reel')->assertSee('In the Reel')->assertSee('Make the Reel')->assertDontSee('Upload at least two videos');

    config(['services.ffmpeg.path' => '/nowhere/ffmpeg']);
    $this->actingAs($this->user)->get(route('clients.assets', $this->client))->assertOk()->assertSee('Install ffmpeg on the server')->assertDontSee('In the Reel');
    $this->actingAs($this->user)->post(route('assets.reel', $this->client), ['clips' => [1, 2], 'seconds' => 3])->assertNotFound();
})->skip(fn () => ReelAssembler::binary() === null, 'ffmpeg is not installed here');

test('ticked clips are queued in file-name order and assembled into one vertical silent Reel in the inbox', function () {
    Storage::fake('local');
    $second = clip($this->client, 'IMG_0002.mp4', 2, '640x480'); // landscape, cut to the chosen seconds
    $first = clip($this->client, 'IMG_0001.mp4', 1, '1080x1920'); // shorter than the cut: plays whole
    $photo = Asset::factory()->create(['client_id' => $this->client->id, 'name' => 'ALMYRA_20261104_ANY_IMG_MISC_EL-EN_v9.jpg']);
    $other = Asset::factory()->create(['kind' => 'video', 'mime' => 'video/mp4', 'name' => 'OTHER_20261104_ANY_VID_MISC_EL-EN_v1.mp4']);

    Queue::fake();
    $url = route('assets.reel', $this->client);
    $this->actingAs($this->user)->post($url, ['clips' => [$second->id], 'seconds' => 3])->assertSessionHasErrorsIn('reel', ['clips']);
    $this->actingAs($this->user)->post($url, ['clips' => [$second->id, $photo->id], 'seconds' => 3])->assertSessionHasErrorsIn('reel', ['clips.1']);
    $this->actingAs($this->user)->post($url, ['clips' => [$second->id, $other->id], 'seconds' => 3])->assertSessionHasErrorsIn('reel', ['clips.1']);
    $this->actingAs($this->user)->post($url, ['clips' => [$second->id, $first->id], 'seconds' => 30])->assertSessionHasErrorsIn('reel', ['seconds']);
    Queue::assertNothingPushed();

    $this->actingAs($this->user)->post($url, ['clips' => [$second->id, $first->id], 'seconds' => 3, 'headline' => 'Sunset at the pool: see you tonight', 'pillar' => 'place'])
        ->assertRedirect(route('clients.assets', $this->client))->assertSessionHas('status', 'Assembling the Reel, it lands in this inbox in a minute or two');
    Queue::assertPushed(AssembleReel::class, fn (AssembleReel $job) => $job->client->is($this->client) && $job->clipIds === [$first->id, $second->id] && $job->seconds === 3 && $job->headline === 'Sunset at the pool: see you tonight' && $job->pillar === 'place');

    (new AssembleReel($this->client, [$first->id, $second->id], 3, 'Sunset at the pool: see you tonight', 'place'))->handle(new ReelAssembler);
    $reel = $this->client->assets()->where('uploaded_via', 'reel')->sole();
    expect($reel)->toMatchArray(['name' => 'ALMYRA_'.now()->format('Ymd').'_ANY_VID_PLACE_EL-EN_v1.mp4', 'original_name' => 'reel.mp4', 'kind' => 'video', 'mime' => 'video/mp4', 'pillar' => 'place', 'note' => 'Reel from: IMG_0001.mp4, IMG_0002.mp4'])
        ->and($reel->size)->toBeGreaterThan(10000);

    $probe = json_decode((new Process([dirname(ReelAssembler::binary()).'/ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', Storage::disk('local')->path($reel->path)]))->mustRun()->getOutput(), true);
    expect($probe['streams'])->toHaveCount(1) // video only, no sound
        ->and($probe['streams'][0])->toMatchArray(['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1080, 'height' => 1920])
        ->and((float) $probe['format']['duration'])->toBeGreaterThan(2.8)->toBeLessThan(3.3); // 1 s whole + 2 s cut
})->skip(fn () => ReelAssembler::binary() === null, 'ffmpeg is not installed here');
