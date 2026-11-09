<?php

use App\Http\Controllers\AssetController;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Planning\PlanRequest;
use App\Posts\PostState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/** A client whose profile languages are these, the rest of the profile as the factory makes it. */
function clientSpeaking(array $languages, array $attributes = []): Client
{
    $client = Client::factory()->create($attributes);
    $client->update(['profile' => ['languages' => $languages] + $client->profile]);

    return $client->fresh();
}

beforeEach(function () {
    $this->travelTo('2026-11-09 10:00:00');
    config(['app.locale' => 'el', 'app.default_locale' => 'el']); // the production default, so a fallback to Greek would show
});

test('a client whose languages the app does not speak gets English, not the Greek default', function () {
    expect(Client::uiLocaleFor(['languages' => ['de', 'en']]))->toBe('en')
        ->and(Client::uiLocaleFor(['languages' => ['de', 'fr']]))->toBe('en')
        ->and(Client::uiLocaleFor(['languages' => ['de', 'el']]))->toBe('el') // Greek is one of its languages
        ->and(Client::uiLocaleFor(['languages' => ['el', 'en']]))->toBe('el')
        ->and(Client::uiLocaleFor(['languages' => ['en', 'el']]))->toBe('en')
        ->and(Client::uiLocaleFor(['languages' => []]))->toBe('el') // no languages at all: the app default
        ->and(clientSpeaking(['de', 'en'])->uiLocale())->toBe('en');
});

test('the client pages and the plan follow it: a German and English client reads English', function () {
    $client = clientSpeaking(['de', 'en'], ['slug' => 'seeblick']);
    $inbox = URL::temporarySignedRoute('inbox.show', now()->addDays(AssetController::LINK_DAYS), ['client' => $client->slug]);
    $this->get($inbox)->assertOk()->assertSee('<html lang="en">', false);

    $plan = fn (Client $c) => (new PlanRequest($c->toProfileArray(), 1, [PlanRequest::plannable($c->toProfileArray())[0]], CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31')))->promptVars()['plan_language'];
    expect($plan($client))->toBe('English')
        ->and($plan(clientSpeaking(['el', 'en'])))->toBe('Greek')
        ->and($plan(clientSpeaking(['en'])))->toBe('English');
});

test('the post form sets and clears the repeat', function () {
    $staff = User::factory()->create(['locale' => 'en']);
    $client = Client::factory()->create();
    $pillar = array_key_first($client->profile['pillars']);
    $form = fn (string $repeat) => ['platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'brief' => 'Sunday brunch', 'scheduled_at' => '2026-11-15 11:00', 'settings' => ['repeat' => $repeat]];

    $this->actingAs($staff)->get(route('posts.create', $client))->assertOk()
        ->assertSee('name="settings[repeat]"', false)->assertSee('Does not repeat')->assertSee('Every week')->assertSee('Every month');

    $this->actingAs($staff)->post(route('posts.store', $client), $form('weekly'))->assertSessionHasNoErrors();
    $post = Post::latest('id')->firstOrFail();
    expect($post->settings['repeat'])->toBe('weekly');
    $this->actingAs($staff)->get(route('posts.edit', $post))->assertOk()->assertSee('<option value="weekly" selected', false);

    $this->actingAs($staff)->patch(route('posts.update', $post), $form(''))->assertSessionHasNoErrors();
    expect($post->fresh()->settings['repeat'] ?? null)->toBeNull();
    $this->actingAs($staff)->post(route('posts.store', $client), $form('daily'))->assertSessionHasErrors('settings.repeat');
});

test('changing only the repeat keeps an approved post approved; a content change still sends it back to review', function () {
    $client = Client::factory()->create();
    $post = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Scheduled, 'scheduled_at' => '2026-11-15 11:00', 'settings' => ['first_comment' => '#chania']]);

    $post->update(['settings' => ['repeat' => 'monthly'] + $post->settings]);
    expect($post->fresh()->state)->toBe(PostState::Scheduled)->and($post->fresh()->settings['repeat'])->toBe('monthly');

    $post->update(['settings' => ['first_comment' => '#crete'] + $post->settings]);
    expect($post->fresh()->state)->toBe(PostState::InReview);
});
