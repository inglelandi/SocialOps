<?php

/* Second deep scan of 2026-11-06, clients, assets, intake, settings and logins: each test fails on the code before it. */

use App\Jobs\AssembleReel;
use App\Jobs\PullFolder;
use App\Media\ReelAssembler;
use App\Models\Asset;
use App\Models\ClaudeCall;
use App\Models\Client;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

describe('the Settings page', function () {
    beforeEach(function () {
        $this->env = base_path('.env.scan-clients-test');
        $this->app->loadEnvironmentFrom('.env.scan-clients-test');
        $this->admin = User::factory()->create(['is_admin' => true]);
    });

    afterEach(function () {
        foreach ([$this->env, $this->env.'.backup', $this->env.'.tmp'] as $file) {
            is_file($file) && unlink($file);
        }
    });

    test('a save keeps a value of several lines, writes new ones escaped on one line and changes only what was changed', function () {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----\n";
        file_put_contents($this->env, "APP_NAME=Social\nAPP_KEY=base64:secret\n# the key, as pasted\nPRIVATE_KEY=\"{$pem}\"\nMAIL_FROM_NAME=\"\${APP_NAME}\"\nFEATURE_CLAUDE_API=false\nFEATURE_CLAUDE_API=false\n");
        chmod($this->env, 0600);
        Artisan::shouldReceive('call')->once()->with('config:cache');

        // the key is in a textarea with its line breaks, so the browser sends it back whole (with CRLF)
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('<textarea id="env-PRIVATE_KEY"', false);
        $note = "two lines\nwith \"quotes\", \$HOME, a back\\slash and a # ";
        $this->actingAs($this->admin)->post('/settings', [
            'values' => ['APP_NAME' => 'Social', 'PRIVATE_KEY' => str_replace("\n", "\r\n", $pem), 'MAIL_FROM_NAME' => 'Social', 'FEATURE_CLAUDE_API' => 'true'],
            'new_name' => 'SLACK_NOTE', 'new_value' => $note,
        ])->assertRedirect('/settings')->assertSessionHas('status', 'settings-saved');

        $saved = file_get_contents($this->env);
        expect(Dotenv::parse($saved))->toMatchArray(['APP_KEY' => 'base64:secret', 'PRIVATE_KEY' => $pem, 'MAIL_FROM_NAME' => 'Social', 'FEATURE_CLAUDE_API' => 'true', 'SLACK_NOTE' => $note])
            ->and($saved)->toContain("# the key, as pasted\nPRIVATE_KEY=\"{$pem}\"\n") // untouched lines stay as written
            ->and($saved)->toContain('MAIL_FROM_NAME="${APP_NAME}"') // and keep following APP_NAME
            ->and(substr_count($saved, "FEATURE_CLAUDE_API=true\n"))->toBe(2) // a name written twice changes on both lines (the last one counts)
            ->and($saved)->toContain('SLACK_NOTE="two lines\nwith \"quotes\", \$HOME, a back\\\\slash and a # "'."\n")
            ->and(fileperms($this->env) & 0777)->toBe(0600)
            ->and(file_get_contents($this->env.'.backup'))->toContain('FEATURE_CLAUDE_API=false');
    });

    test('a save whose file would not load back as typed is refused and .env stays as it was', function () {
        Artisan::shouldReceive('call')->never();

        // a bare name after a value unsets it again: the new value would not read back, so nothing is written
        file_put_contents($this->env, $before = "APP_KEY=base64:secret\nGREETING=hello\nGREETING\n");
        $this->actingAs($this->admin)->from('/settings')->post('/settings', ['values' => ['GREETING' => 'hi']])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['settings' => 'Nothing was saved, the .env file is as it was: the value of GREETING would not read back as typed.']);
        expect(file_get_contents($this->env))->toBe($before)->and(is_file($this->env.'.backup'))->toBeFalse();

        // a .env that does not load at all is not rewritten either
        file_put_contents($this->env, $broken = "APP_KEY=base64:secret\nKEY=\"never closed\n");
        $this->actingAs($this->admin)->from('/settings')->post('/settings', ['values' => ['KEY' => 'x']])->assertSessionHasErrors('settings');
        expect(file_get_contents($this->env))->toBe($broken)->and(is_file($this->env.'.backup'))->toBeFalse();
    });
});

test('two pulls of one watched folder never run at once, and a file is filed once even when another pull files it meanwhile', function () {
    Storage::fake('local');
    config(['services.dropbox.app_key' => 'dkey', 'filesystems.default' => 'local']);
    $client = Client::factory()->create(['code' => 'ALMYRA']);
    $account = $client->accounts()->create(['platform' => 'dropbox', 'external_id' => 'dbid:abc', 'name' => 'Panos', 'access_token' => 'at', 'token_expires_at' => now()->addHour(), 'meta' => ['folder' => '/almyra', 'folder_name' => 'Almyra photos']]);
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => [['.tag' => 'file', 'id' => 'id:f1', 'name' => 'pool.jpg'], ['.tag' => 'file', 'id' => 'id:f2', 'name' => 'bar.jpg']], 'cursor' => 'c', 'has_more' => false]),
        'content.dropboxapi.com/2/files/download' => function (HttpRequest $request) use ($client) {
            if ($request->header('Dropbox-API-Arg')[0] === '{"path":"id:f1"}') {
                // the other pull of the folder files pool.jpg while this one is still downloading it
                $client->storeAsset(UploadedFile::fake()->image('pool.jpg'), ['uploaded_via' => 'dropbox', 'source_id' => 'dropbox:id:f1']);
            }

            return Http::response("\xFF\xD8\xFF\xE0JFIF photo");
        },
    ]);

    // while a pull of the folder holds its lock, another one does nothing
    $lock = Cache::lock((new PullFolder($account))->middleware()[0]->getLockKey(new PullFolder($account)), 60);
    expect($lock->get())->toBeTrue();
    PullFolder::dispatch($account);
    Http::assertNothingSent();
    $lock->release();

    PullFolder::dispatch($account);
    expect($client->assets()->where('source_id', 'dropbox:id:f1')->count())->toBe(1)
        ->and($client->assets()->orderBy('id')->pluck('source_id')->all())->toBe(['dropbox:id:f1', 'dropbox:id:f2'])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2) // no stray copy on the disk
        ->and($account->fresh()->meta)->toMatchArray(['files' => 2, 'new' => 1]);

    // and the database refuses a second row for the same file of the same client
    expect(fn () => Asset::factory()->create(['client_id' => $client->id, 'source_id' => 'dropbox:id:f1']))->toThrow(UniqueConstraintViolationException::class);
});

test('a Reel headline with "%" or a backslash is drawn as written: from a text file, without expansion, local files only', function () {
    $arguments = (new ReelAssembler)->arguments(['/clips/a.mp4', '/clips/b.mp4'], 3, '/tmp/headline', '/tmp/reel.mp4');
    $graph = $arguments[array_search('-filter_complex', $arguments, true) + 1];

    expect($graph)->toContain(':textfile=/tmp/headline:expansion=none:')
        ->and($graph)->not->toContain(':text=')
        ->and(array_count_values($arguments)['-protocol_whitelist'])->toBe(2)
        ->and(array_slice($arguments, 4, 6))->toBe(['-protocol_whitelist', 'file', '-t', '3', '-i', '/clips/a.mp4'])
        ->and(end($arguments))->toBe('/tmp/reel.mp4');

    // a path with filtergraph characters is escaped for the filter and for the graph
    expect((new ReelAssembler)->arguments(['/clips/a.mp4'], 3, "/tmp/it's:here", '/tmp/reel.mp4'))->toContain('-filter_complex')
        ->and(implode(' ', (new ReelAssembler)->arguments(['/clips/a.mp4'], 3, "/tmp/it's:here", '/tmp/reel.mp4')))->toContain("textfile=/tmp/it\\\\\\'s\\\\:here:expansion=none");

    // no headline, no drawtext
    expect(implode(' ', (new ReelAssembler)->arguments(['/clips/a.mp4', '/clips/b.mp4'], 3, null, '/tmp/reel.mp4')))->not->toContain('drawtext');
});

test('a login limited to some clients sees only their jobs, failed jobs and Claude usage on the Jobs page', function () {
    $mine = Client::factory()->create(['name' => 'Almyra Lounge']);
    $other = Client::factory()->create(['name' => 'Kyma Villas']);
    $account = $mine->accounts()->create(['platform' => 'drive', 'external_id' => 'f', 'name' => 'Almyra photos', 'access_token' => '', 'meta' => ['folder' => 'f', 'folder_name' => 'Almyra photos']]);
    Queue::connection('database')->push(new PullFolder($account)); // the client through the account
    Queue::connection('database')->push(new AssembleReel($other, [1, 2], 3, '', null)); // the client itself
    [$minePayload, $otherPayload] = DB::table('jobs')->orderBy('id')->pluck('payload')->all();
    DB::table('failed_jobs')->insert([
        ['uuid' => 'mine-1', 'connection' => 'database', 'queue' => 'default', 'payload' => $minePayload, 'exception' => 'RuntimeException: Drive said no', 'failed_at' => now()],
        ['uuid' => 'other-1', 'connection' => 'database', 'queue' => 'default', 'payload' => $otherPayload, 'exception' => 'RuntimeException: ffmpeg said no', 'failed_at' => now()],
    ]);
    ClaudeCall::create(['client_id' => $mine->id, 'purpose' => 'caption', 'model' => 'm', 'input_tokens' => 1000, 'output_tokens' => 100]);
    ClaudeCall::create(['client_id' => $other->id, 'purpose' => 'plan', 'model' => 'm', 'input_tokens' => 9000, 'output_tokens' => 900]);
    $limited = User::factory()->create(['client_ids' => [$mine->id]]);

    $this->actingAs($limited)->get('/jobs')->assertOk()
        ->assertSee('App\Jobs\PullFolder')->assertSee('Drive said no')->assertSee('Almyra Lounge')->assertSee('1,000')
        ->assertDontSee('App\Jobs\AssembleReel')->assertDontSee('ffmpeg said no')->assertDontSee('Kyma Villas')->assertDontSee('9,000');
    $this->actingAs($limited)->post('/jobs/other-1/retry')->assertForbidden();
    $this->actingAs($limited)->delete('/jobs/other-1')->assertForbidden();
    Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['mine-1']]);
    $this->actingAs($limited)->post('/jobs/mine-1/retry')->assertRedirect('/jobs');

    $this->actingAs(User::factory()->create())->get('/jobs')->assertOk()
        ->assertSee('App\Jobs\AssembleReel')->assertSee('ffmpeg said no')->assertSee('Kyma Villas')->assertSee('9,000');
});

test('only a login that sees every client can add clients', function () {
    $client = Client::factory()->create();
    $limited = User::factory()->create(['client_ids' => [$client->id]]);

    $this->actingAs($limited)->get('/clients')->assertOk()->assertDontSee('New client')->assertDontSee(route('clients.create'));
    $this->actingAs($limited)->get('/clients/create')->assertForbidden();
    $this->actingAs($limited)->post('/clients', ['name' => 'Sneaky Villas', 'slug' => 'sneaky', 'code' => 'SNEAKY'])->assertForbidden();
    expect(Client::count())->toBe(1);

    $this->actingAs(User::factory()->create())->get('/clients')->assertSee('New client')->assertSee(route('clients.create'));
    $this->actingAs(User::factory()->create())->get('/clients/create')->assertOk();
});

test('the client upload page speaks the client language when a file or the whole send is too big', function () {
    Storage::fake(config('filesystems.default'));
    $greek = Client::factory()->create(['slug' => 'almyra']);
    $english = Client::factory()->create(['slug' => 'kyma']);
    $english->forceFill(['profile' => ['languages' => ['en']] + $english->profile])->save();
    $url = fn (Client $client) => URL::temporarySignedRoute('inbox.show', now()->addDays(30), ['client' => $client->slug]);
    $refused = fn () => new UploadedFile(tempnam(sys_get_temp_dir(), 'big'), 'sunset.mov', 'video/quicktime', UPLOAD_ERR_INI_SIZE, true); // over upload_max_filesize

    $this->from($url($english))->post($url($english), ['files' => [$refused()]])
        ->assertSessionHasErrors(['files.0' => __('A file did not arrive whole. It may be bigger than the server takes (:mb MB a file): try it on its own, or send a smaller version.', ['mb' => (int) floor(UploadedFile::getMaxFilesize() / 1048576)])]);
    $this->from($url($english))->post($url($english), [])->assertSessionHasErrors(['files' => 'Please choose at least one photo or video.']);
    $this->from($url($greek))->followingRedirects()->post($url($greek), ['files' => [$refused()]])
        ->assertSee('Ένα αρχείο δεν έφτασε ολόκληρο')->assertDontSee('files.0');

    // more than post_max_size in one send: PHP drops the whole body before the form is read
    $this->call('POST', $url($greek), [], [], [], ['CONTENT_LENGTH' => 10 ** 12])
        ->assertStatus(413)->assertSee('<html lang="el">', false)->assertSee('Πάρα πολλά μαζί')->assertSee('href="'.e(parse_url($url($greek), PHP_URL_PATH).'?'.parse_url($url($greek), PHP_URL_QUERY)).'"', false);
    $this->call('POST', $url($english), [], [], [], ['CONTENT_LENGTH' => 10 ** 12])->assertStatus(413)->assertSee('Too much at once');
});

test('the only admin and the last login cannot delete themselves on the profile page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $colleague = User::factory()->create();

    $this->actingAs($admin)->from('/profile')->delete('/profile', ['password' => 'password'])
        ->assertRedirect('/profile')->assertSessionHasErrorsIn('userDeletion', ['password' => 'This is the only admin login. Make another login admin on the Staff page first.']);
    expect($admin->fresh())->not->toBeNull();

    // the last login of all: /setup would open to anyone
    $admin->delete();
    $this->actingAs($colleague)->from('/profile')->delete('/profile', ['password' => 'password'])
        ->assertSessionHasErrorsIn('userDeletion', ['password' => 'This is the last login: without one, anyone could make a new one on the setup page. Add another login on the Staff page first.']);
    expect($colleague->fresh())->not->toBeNull();

    // with a second admin the first can go
    $first = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['is_admin' => true]);
    $this->actingAs($first)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');
    expect($first->fresh())->toBeNull();
});

test('clients:import reports a profile that is not valid YAML and goes on with the others', function () {
    $dir = storage_path('framework/testing/clients-scan');
    File::ensureDirectoryExists($dir);
    File::put($dir.'/a-typo.yaml', "slug: typo\nname: [Typo\n"); // sorts first: it used to stop the run there
    File::put($dir.'/almyra.yaml', file_get_contents(base_path('config/clients/example.yaml')));

    $this->artisan('clients:import', ['dir' => 'storage/framework/testing/clients-scan'])
        ->expectsOutputToContain('a-typo.yaml: not valid YAML')
        ->expectsOutputToContain('almyra.yaml: created')
        ->assertExitCode(1);
    expect(Client::pluck('slug')->all())->toBe(['almyra']);

    File::deleteDirectory($dir);
});
