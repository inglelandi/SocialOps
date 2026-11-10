<?php

use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\SavePlan;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Planning\ClaudeQueue;
use App\Posts\PostState;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('features.claude_api', false)); // the queue is the MCP mode's (production runs with the flag off)

function planJob(Client $client, array $fields, ?string $at = null): Report
{
    $job = $client->reports()->create(['kind' => 'claude_job', 'title' => "Plan {$fields['posts']} posts", 'body' => $fields['instruction'] ?? '', 'meta' => $fields]);
    if ($at) {
        $job->forceFill(['created_at' => $at])->save();
    }

    return $job;
}

test('the Plan with Claude box lists the client\'s waiting requests, each with a Cancel button', function () {
    $this->travelTo('2026-11-06 12:00:00');
    $client = Client::factory()->create(['name' => 'Almyra']);
    $other = Client::factory()->create(['name' => 'Kima']);
    $staff = User::factory()->create();
    planJob($client, ['posts' => 12, 'from' => '2026-12-01', 'days' => 30, 'instruction' => 'Christmas opening hours'], '2026-11-06 09:15:00');
    $plain = planJob($client, ['posts' => 4, 'from' => '2026-11-10', 'days' => 7], '2026-11-06 11:30:00');
    planJob($other, ['posts' => 9, 'from' => '2027-01-01', 'days' => 31]);

    $this->actingAs($staff)->get(route('clients.planner', $client))->assertOk()
        ->assertSeeInOrder(['Waiting for Claude', '12 posts from 01-12-2026 for 30 days', 'About:', 'Christmas opening hours', 'queued 06-11-2026 09:15', '2 hours ago', 'over 2 hours', 'Cancel',
            '4 posts from 10-11-2026 for 7 days', 'queued 06-11-2026 11:30', '30 minutes ago', route('claude.cancel', $plain)])
        ->assertDontSee('9 posts from');
    $this->actingAs($staff)->get(route('clients.planner', $other))->assertOk()->assertDontSee('12 posts from');

    $this->actingAs($staff)->from(route('clients.planner', $client))->delete(route('claude.cancel', $plain))->assertRedirect(route('clients.planner', $client));
    expect(Report::find($plain->id))->toBeNull()->and($client->reports()->where('kind', 'claude_job')->count())->toBe(1);
    $this->actingAs($staff)->get(route('clients.planner', $client))->assertDontSee('4 posts from');
});

test('only staff cancel, a limited login only its own clients\' requests, and only queue rows', function () {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $job = planJob($other, ['posts' => 6, 'from' => '2027-05-01', 'days' => 31]);
    $sheet = $other->reports()->create(['kind' => 'plan', 'title' => 'Plan', 'body' => 'x', 'meta' => []]);
    $limited = User::factory()->create(['client_ids' => [$client->id]]);

    $this->delete(route('claude.cancel', $job))->assertRedirect('/login');
    $this->actingAs($limited)->delete(route('claude.cancel', $job))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('claude.cancel', $sheet))->assertNotFound();
    expect(Report::whereKey([$job->id, $sheet->id])->count())->toBe(2);
});

test('pressing Queue for Claude again with the same request adds nothing and says it is already waiting', function () {
    $this->travelTo('2026-11-06 10:00:00');
    $client = Client::factory()->create();
    $staff = User::factory()->create();
    $request = ['posts' => 6, 'from' => '2027-05-01', 'days' => 31, 'instruction' => 'Full moon parties'];

    $this->actingAs($staff)->post(route('clients.queue', $client), $request)->assertRedirect(route('clients.planner', $client))->assertSessionHas('status', 'job-queued');
    $this->travel(20)->minutes();
    foreach ([$request, ['instruction' => '  Full moon parties  '] + $request] as $again) {
        $this->actingAs($staff)->post(route('clients.queue', $client), $again)->assertRedirect(route('clients.planner', $client))->assertSessionHas('status', 'job-already-waiting');
    }
    expect($client->reports()->where('kind', 'claude_job')->count())->toBe(1);

    $this->actingAs($staff)->post(route('clients.queue', $client), $request)->assertSessionHas('status', 'job-already-waiting');
    $this->actingAs($staff)->get(route('clients.planner', $client))->assertSee('Already waiting for Claude (queued 06-11-2026 10:00)');

    // a different request, or the same one for another client, is a new job
    $this->actingAs($staff)->post(route('clients.queue', $client), ['instruction' => 'Weddings'] + $request)->assertSessionHas('status', 'job-queued');
    $this->actingAs($staff)->post(route('clients.queue', $client), ['days' => 30] + $request)->assertSessionHas('status', 'job-queued');
    $this->actingAs($staff)->post(route('clients.queue', Client::factory()->create()), $request)->assertSessionHas('status', 'job-queued');
    expect(Report::where('kind', 'claude_job')->count())->toBe(4);
});

test('the Jobs page lists every plan and caption request of the login\'s clients, with Cancel and Stop asking', function () {
    $this->travelTo('2026-11-06 12:00:00');
    $mine = Client::factory()->create(['name' => 'Almyra']);
    $theirs = Client::factory()->create(['name' => 'Kima']);
    $limited = User::factory()->create(['client_ids' => [$mine->id]]);
    $everyone = User::factory()->create();
    planJob($mine, ['posts' => 12, 'from' => '2026-12-01', 'days' => 30, 'instruction' => 'Christmas'], '2026-11-06 08:00:00');
    planJob($theirs, ['posts' => 9, 'from' => '2027-01-01', 'days' => 31]);
    $this->travelTo('2026-11-06 11:00:00');
    $asked = Post::factory()->create(['client_id' => $mine->id, 'name' => 'ALMYRA_ASKED', 'settings' => ['ask_claude' => true]]);
    $approved = Post::factory()->create(['client_id' => $mine->id, 'name' => 'ALMYRA_APPROVED', 'state' => PostState::Approved, 'settings' => ['ask_claude' => true, 'first_comment' => 'Hi']]);
    Post::factory()->create(['client_id' => $theirs->id, 'name' => 'KIMA_ASKED', 'settings' => ['ask_claude' => true]]);
    Post::factory()->create(['client_id' => $mine->id, 'name' => 'ALMYRA_NOT_ASKED', 'settings' => ['ask_claude' => false]]);
    Post::factory()->create(['client_id' => $mine->id, 'name' => 'ALMYRA_PUBLISHED', 'state' => PostState::Published, 'settings' => ['ask_claude' => true]]);
    Post::factory()->create(['client_id' => $mine->id, 'name' => 'ALMYRA_BINNED', 'settings' => ['ask_claude' => true]])->moveToBin();
    $this->travelTo('2026-11-06 12:00:00');

    $this->actingAs($limited)->get('/jobs')->assertOk()
        ->assertSeeInOrder(['Waiting for Claude', '12 posts from 01-12-2026 for 30 days', 'Christmas', route('clients.planner', $mine), '06-11-2026 08:00', '4 hours ago', 'over 2 hours', 'Cancel',
            route('posts.edit', $asked), 'ALMYRA_ASKED', '06-11-2026 11:00', '1 hour ago', route('claude.stop', $asked), 'Stop asking', 'ALMYRA_APPROVED'])
        ->assertDontSee('Kima')->assertDontSee('KIMA_ASKED')->assertDontSee('9 posts from')
        ->assertDontSee('ALMYRA_NOT_ASKED')->assertDontSee('ALMYRA_PUBLISHED')->assertDontSee('ALMYRA_BINNED');
    $this->actingAs($everyone)->get('/jobs')->assertOk()->assertSee(['9 posts from 01-01-2027 for 31 days', 'KIMA_ASKED', 'ALMYRA_ASKED']);

    // Stop asking unticks the post and leaves an approved one approved; not for another client's post
    $this->actingAs($limited)->post(route('claude.stop', Post::where('name', 'KIMA_ASKED')->first()))->assertForbidden();
    $this->actingAs($limited)->from('/jobs')->post(route('claude.stop', $approved))->assertRedirect('/jobs');
    expect($approved->fresh())->state->toBe(PostState::Approved)->settings->toBe(['ask_claude' => false, 'first_comment' => 'Hi']);
    $this->actingAs($limited)->get('/jobs')->assertSee('Caption request cancelled')->assertDontSee('ALMYRA_APPROVED');
    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()->assertDontSee('"post":'.$approved->id.',');

    $this->actingAs($limited)->from('/jobs')->post(route('claude.stop', $asked))->assertRedirect('/jobs');
    $this->actingAs($limited)->from('/jobs')->delete(route('claude.cancel', Report::where('client_id', $mine->id)->first()))->assertRedirect('/jobs');
    $this->actingAs($limited)->get('/jobs')->assertSee('Plan request cancelled')->assertSee('Nothing waiting for Claude.');
    $this->actingAs($everyone)->get('/jobs')->assertSee('KIMA_ASKED')->assertDontSee('Nothing waiting for Claude.');
});

test('the tick keeps its time: set when it goes on, gone when it goes off, older ticks count from the last change', function () {
    $this->travelTo('2026-11-06 09:00:00');
    $client = Client::factory()->create();
    $staff = User::factory()->create();
    $pillar = array_key_first($client->profile['pillars']);
    $post = Post::factory()->create(['client_id' => $client->id, 'platforms' => ['instagram'], 'format' => 'feed']);
    $form = fn (string $tick) => ['platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'brief' => 'Sunset', 'settings' => ['ask_claude' => $tick]];

    $this->actingAs($staff)->patch(route('posts.update', $post), $form('1'))->assertSessionHasNoErrors();
    expect($post->fresh()->settings)->toHaveKey('ask_claude_at')->and($post->fresh()->settings['ask_claude'])->toBeTrue();
    $this->travel(1)->hours();
    $this->actingAs($staff)->patch(route('posts.update', $post), $form('1'))->assertSessionHasNoErrors(); // saved again: still asked since 09:00
    expect(ClaudeQueue::askedAt($post->fresh())->format('d-m-Y H:i'))->toBe('06-11-2026 09:00');
    $this->actingAs($staff)->patch(route('posts.update', $post), $form('0'))->assertSessionHasNoErrors();
    expect($post->fresh()->settings)->not->toHaveKey('ask_claude_at')->and($post->fresh()->settings['ask_claude'])->toBeFalse();

    $legacy = Post::factory()->create(['client_id' => $client->id]);
    $legacy->forceFill(['settings' => ['ask_claude' => true]])->saveQuietly(); // ticked before the time was kept
    expect(ClaudeQueue::askedAt($legacy->fresh())->format('d-m-Y H:i'))->toBe('06-11-2026 10:00');
});

test('a request left for over 2 hours puts a box on the dashboard and one Slack line per request, once', function () {
    config(['services.slack.webhook_url' => 'https://hooks.slack.com/services/t']);
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    $this->travelTo('2026-11-06 08:00:00');
    $mine = Client::factory()->create(['name' => 'Almyra']);
    $theirs = Client::factory()->create(['name' => 'Kima']);
    $limited = User::factory()->create(['client_ids' => [$mine->id]]);
    $job = planJob($mine, ['posts' => 6, 'from' => '2027-05-01', 'days' => 31]);
    $post = Post::factory()->create(['client_id' => $theirs->id, 'name' => 'KIMA_CAPTIONS', 'settings' => ['ask_claude' => true]]);
    $this->travelTo('2026-11-06 09:30:00');
    planJob($mine, ['posts' => 2, 'from' => '2027-05-01', 'days' => 7]); // 1 hour later: not late yet

    $this->actingAs($limited)->get('/dashboard')->assertOk()->assertDontSee('Claude has not picked up');
    $this->artisan('claude:check-queue')->assertSuccessful();
    Http::assertNothingSent();

    $this->travelTo('2026-11-06 10:20:00');
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
        ->assertSee('Claude has not picked up 2 requests for over 2 hours. Check that the scheduled task is on.')->assertSee(route('jobs.index'));
    $this->actingAs($limited)->get('/dashboard')->assertOk()->assertSee('Claude has not picked up 1 requests for over 2 hours.');

    $this->artisan('claude:check-queue')->assertSuccessful();
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Almyra: Claude has not picked up the plan request "Plan 6 posts", queued 06-11-2026 08:00. Check that the scheduled task is on.') && str_contains($r['text'], route('clients.planner', $mine)));
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Kima: Claude has not picked up the captions of KIMA_CAPTIONS, queued 06-11-2026 08:00.') && str_contains($r['text'], route('posts.edit', $post)));
    expect($job->fresh()->meta)->toHaveKey('alerted_at')->and($post->fresh()->updated_at->format('H:i'))->toBe('08:00'); // the post was not "changed"

    $this->travelTo('2026-11-06 11:20:00');
    $this->artisan('claude:check-queue')->assertSuccessful(); // the others were told already, the 09:30 one is not late yet
    Http::assertSentCount(2);
    $this->travelTo('2026-11-06 12:20:00');
    $this->artisan('claude:check-queue')->assertSuccessful();
    Http::assertSentCount(3);
    $this->travelTo('2026-11-06 13:20:00');
    $this->artisan('claude:check-queue')->assertSuccessful();
    Http::assertSentCount(3);
    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()->assertDontSee('alerted_at');

    // asked again later, the same post is a new request
    $post->update(['settings' => ['ask_claude' => false]]);
    $post->update(['settings' => ['ask_claude' => true]]);
    expect($post->fresh()->settings)->not->toHaveKey('ask_claude_alerted_at');
    $this->travel(3)->hours();
    $this->artisan('claude:check-queue')->assertSuccessful();
    Http::assertSentCount(4);

    $this->artisan('schedule:list')->expectsOutputToContain('claude:check-queue');
});

test('save-plan clears the job and identical requests that slipped in next to it, nothing else', function () {
    $client = Client::factory()->create(['slug' => 'almyra']);
    $pillar = array_key_first($client->profile['pillars']);
    $request = ['posts' => 6, 'from' => '2027-05-01', 'days' => 31, 'instruction' => 'Full moon parties'];
    $job = planJob($client, $request);
    $twin = planJob($client, ['instruction' => 'Full moon parties '] + $request);
    $different = planJob($client, ['instruction' => 'Weddings'] + $request);
    $elsewhere = planJob(Client::factory()->create(), $request);

    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'job' => $job->id, 'posts' => [['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'title' => 'Full moon', 'angle' => 'Party on the beach.']]])->assertOk();

    expect(Report::where('kind', 'claude_job')->pluck('id')->all())->toEqualCanonicalizing([$different->id, $elsewhere->id])
        ->and(Report::find($twin->id))->toBeNull();
});
