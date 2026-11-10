<?php

use App\Clients\ClientProfile;
use App\Jobs\PlanContentBatch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

function clientInput(array $overrides = []): array
{
    return $overrides + [
        'slug' => 'almyra',
        'code' => 'almyra',
        'name' => 'Almyra Beach Lounge',
        'type' => 'beach_bar',
        'location' => 'Chania, Crete',
        'profile' => ClientProfile::templateYaml(),
    ];
}

test('guests cannot see clients', function () {
    $this->get('/clients')->assertRedirect('/login');
});

test('staff can list, create, edit and delete a client', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get('/clients')->assertOk()->assertSee('No clients yet');

    $this->actingAs($staff)->get('/clients/create')->assertOk()->assertSee('max_per_post');

    $this->actingAs($staff)->post('/clients', clientInput())
        ->assertSessionHasNoErrors()
        ->assertRedirect('/clients');

    $client = Client::where('slug', 'almyra')->firstOrFail();
    expect($client->code)->toBe('ALMYRA')
        ->and($client->profile['hashtags']['max_per_post'])->toBe(5)
        ->and($client->profile['approval']['policy'])->toBe('required')
        ->and(array_keys($client->toProfileArray()))->toBe(['slug', 'code', 'name', 'type', 'location', ...ClientProfile::SECTIONS]);

    $this->actingAs($staff)->get("/clients/{$client->id}/edit")->assertOk()->assertSee('Almyra Beach Lounge');

    $this->actingAs($staff)->patch("/clients/{$client->id}", clientInput(['name' => 'Almyra Lounge']))
        ->assertSessionHasNoErrors();
    expect($client->refresh()->name)->toBe('Almyra Lounge');

    $this->actingAs($staff)->get('/clients')->assertSee('ALMYRA')->assertSee('instagram, facebook, tiktok, linkedin');

    $this->actingAs($staff)->delete("/clients/{$client->id}")->assertRedirect('/clients');
    expect(Client::count())->toBe(0);
});

test('a profile is rejected when it is not valid YAML, pillars do not add up, or auto-approve is misused', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->post('/clients', clientInput(['profile' => "accounts: [\n"]))
        ->assertSessionHasErrors('profile');

    $bad = str_replace('place: 25', 'place: 30', ClientProfile::templateYaml());
    $this->actingAs($staff)->post('/clients', clientInput(['profile' => $bad]))
        ->assertSessionHasErrors(['profile' => 'Pillar shares must add up to 100.']);

    $bad = str_replace('auto_approve_on_timeout: false', 'auto_approve_on_timeout: true', ClientProfile::templateYaml());
    $this->actingAs($staff)->post('/clients', clientInput(['profile' => $bad]))
        ->assertSessionHasErrors('profile');

    expect(Client::count())->toBe(0);
});

test('slug and code must be unique and well formed', function () {
    $staff = User::factory()->create();
    Client::factory()->create(['slug' => 'almyra', 'code' => 'ALMYRA']);

    $this->actingAs($staff)->post('/clients', clientInput(['slug' => 'other']))
        ->assertSessionHasErrors('code');
    $this->actingAs($staff)->post('/clients', clientInput(['code' => 'OTHER']))
        ->assertSessionHasErrors('slug');
    $this->actingAs($staff)->post('/clients', clientInput(['slug' => 'Bad Slug', 'code' => 'TOO-LONG-CODE-1']))
        ->assertSessionHasErrors(['slug', 'code']);
});

test('clients:import creates and updates clients from YAML files and skips the template', function () {
    $dir = storage_path('framework/testing/clients');
    File::ensureDirectoryExists($dir);
    File::copy(base_path('config/clients/example.yaml'), $dir.'/example.yaml');
    File::put($dir.'/almyra.yaml', file_get_contents(base_path('config/clients/example.yaml')));
    File::put($dir.'/broken.yaml', "slug: broken\nname: Broken\n");

    $this->artisan('clients:import', ['dir' => 'storage/framework/testing/clients'])
        ->expectsOutputToContain('almyra.yaml: created')
        ->expectsOutputToContain('broken.yaml:')
        ->assertExitCode(1);

    expect(Client::count())->toBe(1)
        ->and(Client::first()->code)->toBe('ALMYRA');

    File::put($dir.'/almyra.yaml', str_replace('name: Almyra Beach Lounge', 'name: Almyra Lounge', file_get_contents($dir.'/almyra.yaml')));
    File::delete($dir.'/broken.yaml');

    $this->artisan('clients:import', ['dir' => 'storage/framework/testing/clients'])
        ->expectsOutputToContain('almyra.yaml: updated')
        ->assertExitCode(0);

    expect(Client::count())->toBe(1)
        ->and(Client::first()->name)->toBe('Almyra Lounge');

    File::deleteDirectory($dir);
});

test('plan with Claude queues a content:plan run for the chosen accounts and mails the outcome', function () {
    Bus::fake();
    $staff = User::factory()->create();
    $client = Client::factory()->create(['slug' => 'almyra']);
    $from = now()->addDay()->toDateString();

    $this->actingAs($staff)->get("/clients/{$client->id}/plan")->assertOk()->assertSee('Plan with Claude')->assertSee(route('clients.plan', $client));
    $this->actingAs($staff)->post("/clients/{$client->id}/plan", ['posts' => 3, 'platforms' => ['instagram'], 'from' => $from, 'days' => 14])
        ->assertRedirect("/clients/{$client->id}/plan")->assertSessionHas('status', 'plan-started');
    Bus::assertDispatched(PlanContentBatch::class, fn (PlanContentBatch $job) => $job->options['posts'] === 3 && $job->options['platforms'] === ['instagram']);
    $this->actingAs($staff)->post("/clients/{$client->id}/plan", ['posts' => 3, 'platforms' => ['pinterest'], 'from' => '2020-01-01', 'days' => 14])
        ->assertSessionHasErrors(['platforms.0', 'from']);

    Artisan::shouldReceive('call')->once()->with('content:plan', ['client' => 'almyra', '--posts' => 3, '--platforms' => 'instagram', '--from' => $from, '--days' => 14]);
    Artisan::shouldReceive('output')->andReturn('3 Draft posts created.');
    Mail::shouldReceive('raw')->once()->withArgs(fn (string $body) => str_contains($body, '3 Draft posts created.') && str_contains($body, '/calendar?client=almyra'));
    (new PlanContentBatch($client, $staff, ['posts' => 3, 'platforms' => ['instagram'], 'from' => $from, 'days' => 14]))->handle();
});

test('post from a prompt queues a one-post content:plan run with the instruction and the latest brief, and mails the post link', function () {
    Bus::fake();
    $staff = User::factory()->create();
    $client = Client::factory()->create(['slug' => 'almyra']);

    $this->actingAs($staff)->get("/clients/{$client->id}/plan")->assertOk()->assertSee('Post from a prompt');
    $this->actingAs($staff)->post("/clients/{$client->id}/draft", ['instruction' => 'Sunset cocktails this weekend', 'platforms' => ['instagram', 'facebook'], 'date' => ''])
        ->assertRedirect("/clients/{$client->id}/plan")->assertSessionHas('status', 'post-started');
    Bus::assertDispatched(PlanContentBatch::class, fn (PlanContentBatch $job) => $job->options === ['posts' => 1, 'platforms' => ['instagram', 'facebook'], 'from' => now()->addDay()->toDateString(), 'days' => 1, 'instruction' => 'Sunset cocktails this weekend', 'latest_brief' => true, 'brief' => null]);
    $this->actingAs($staff)->post("/clients/{$client->id}/draft", ['instruction' => 'Fresh one', 'platforms' => ['instagram'], 'date' => '2027-05-12', 'fresh' => '1', 'brief' => '# Pasted'])
        ->assertSessionHas('status', 'post-started');
    Bus::assertDispatched(PlanContentBatch::class, fn (PlanContentBatch $job) => $job->options['from'] === '2027-05-12' && $job->options['latest_brief'] === false && $job->options['brief'] === '# Pasted');
    $this->actingAs($staff)->post("/clients/{$client->id}/draft", ['instruction' => '', 'platforms' => ['pinterest']])
        ->assertSessionHasErrorsIn('draft', ['instruction', 'platforms.0']);

    Artisan::shouldReceive('call')->once()->with('content:plan', ['client' => 'almyra', '--posts' => 1, '--platforms' => 'instagram', '--from' => '2027-05-12', '--days' => 1, '--latest-brief' => true, '--instruction' => 'Sunset cocktails this weekend']);
    Artisan::shouldReceive('output')->andReturn("1 Draft posts created.\nPost: http://localhost/posts/7/edit");
    Mail::shouldReceive('raw')->once()->withArgs(fn (string $body, $message) => str_contains($body, 'Post: http://localhost/posts/7/edit'));
    (new PlanContentBatch($client, $staff, ['posts' => 1, 'platforms' => ['instagram'], 'from' => '2027-05-12', 'days' => 1, 'instruction' => 'Sunset cocktails this weekend', 'latest_brief' => true]))->handle();
});

test('a profile written before the research section existed still parses', function () {
    $yaml = preg_replace('/^research:\n(    .*\n)*/m', '', ClientProfile::templateYaml());

    expect($yaml)->not->toContain('research')
        ->and(ClientProfile::parse($yaml)['research'])->toBe(['sources' => [], 'excluded_domains' => [], 'excluded_topics' => []]);
});

test('a profile written before the slots, saved replies and signatures sections existed still parses', function () {
    $yaml = preg_replace('/^(slots|saved_replies|signatures):.*\n/m', '', ClientProfile::templateYaml());

    expect($yaml)->not->toContain('signatures')
        ->and(ClientProfile::parse($yaml))->toMatchArray(['slots' => [], 'saved_replies' => [], 'signatures' => []]);
});
