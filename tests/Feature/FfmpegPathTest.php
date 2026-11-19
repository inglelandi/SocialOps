<?php

use App\Media\ReelAssembler;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

// Panos, 2026-11-19: "/var/www/vhosts/socialops.inglelandi.com/bin/ffmpeg … throws error 500 when i go to the make reels page".
// The Files page asked is_executable() about a path outside the site's open_basedir; PHP's warning became a 500.

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA']);
    $this->actingAs(User::factory()->create());
    $this->dir = sys_get_temp_dir().'/socialops-ffmpeg-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    config(['services.ffmpeg.path' => 'ffmpeg-not-on-this-path']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::delete(ReelAssembler::bundled());
});

test('a path outside the folders the site may open is "no ffmpeg", never a warning', function () {
    $base = base_path().PATH_SEPARATOR.sys_get_temp_dir();
    // only the class itself, not Composer's autoloader: packages that probe the machine on load (agent-detector looks at /opt) would
    // warn under the restriction too, and the check is about ReelAssembler's own lookup
    $code = 'set_error_handler(function ($n, $s) { throw new ErrorException($s); }); require "'.base_path('app/Media/ReelAssembler.php').'";'
        .' echo App\Media\ReelAssembler::runnable("/var/www/vhosts/socialops.example.com/bin/ffmpeg") ? "yes" : "no";';
    $run = new Process([PHP_BINARY, '-d', 'open_basedir='.$base, '-r', $code]);
    $run->run();

    expect(trim($run->getOutput()))->toBe('no')->and($run->getErrorOutput())->toBe('');
});

test('the Reel box says why there is no ffmpeg instead of failing', function () {
    $this->get(route('clients.files', $this->client))->assertOk()
        ->assertSee('Install ffmpeg on the server, or upload the static ffmpeg to '.ReelAssembler::bundled().' and set its permissions to 755');

    config(['services.ffmpeg.path' => "{$this->dir}/missing/ffmpeg"]);
    $this->get(route('clients.files', $this->client))->assertOk()
        ->assertSee("There is no file at {$this->dir}/missing/ffmpeg (FFMPEG_PATH). Upload ffmpeg to ".ReelAssembler::bundled());

    file_put_contents("{$this->dir}/ffmpeg", "#!/bin/sh\n");
    chmod("{$this->dir}/ffmpeg", 0644);
    config(['services.ffmpeg.path' => "{$this->dir}/ffmpeg"]);
    $this->get(route('clients.files', $this->client))->assertOk()
        ->assertSee("The file {$this->dir}/ffmpeg cannot be run: set its permissions to 755.");

    chmod("{$this->dir}/ffmpeg", 0755);
    expect(ReelAssembler::binary())->toBe("{$this->dir}/ffmpeg")->and(ReelAssembler::problem())->toBeNull();
});

test('a static ffmpeg uploaded to storage/bin is used without FFMPEG_PATH, and even when FFMPEG_PATH is wrong', function () {
    expect(ReelAssembler::bundled())->toBe(storage_path('bin/ffmpeg'))->and(ReelAssembler::binary())->toBeNull();

    file_put_contents(ReelAssembler::bundled(), "#!/bin/sh\n");
    chmod(ReelAssembler::bundled(), 0644);
    expect(ReelAssembler::binary())->toBeNull()
        ->and(ReelAssembler::problem())->toBe('The file '.ReelAssembler::bundled().' cannot be run: set its permissions to 755.');

    chmod(ReelAssembler::bundled(), 0755);
    expect(ReelAssembler::binary())->toBe(ReelAssembler::bundled());
    config(['services.ffmpeg.path' => '/var/www/vhosts/socialops.example.com/bin/ffmpeg']);
    expect(ReelAssembler::binary())->toBe(ReelAssembler::bundled());
});
