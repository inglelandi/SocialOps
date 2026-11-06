<?php

use App\Mail\ReviewMail;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\Review;
use App\Models\User;
use App\Posts\PostState;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** Sends the posts through the staff endpoint and returns the review it created. */
function sendForApproval(Client $client, Post ...$posts): Review
{
    test()->actingAs(test()->user)->post(route('reviews.store', $client), ['posts' => collect($posts)->pluck('id')->all()])
        ->assertSessionHasNoErrors()->assertRedirect(route('clients.reviews', $client));

    return Review::latest('id')->first();
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-03 10:00:00');
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Almyra Beach Lounge']); // example.yaml: required, 48 h, owner@example.com
    $this->dated = Post::factory()->create(['client_id' => $this->client->id, 'scheduled_at' => '2026-11-10 10:00']);
    $this->undated = Post::factory()->create(['client_id' => $this->client->id, 'state' => PostState::ChangesRequested]);
});

test('a mail server that is down does not lose the review: the posts go in review, the link exists and the page says the e-mail failed', function () {
    Mail::swap(new MailManager(app())); // the real mailer (Mail::fake() above also replaced the container's) against a closed port
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $this->actingAs($this->user)->post(route('reviews.store', $this->client), ['posts' => [$this->dated->id]])
        ->assertSessionHasNoErrors()->assertRedirect(route('clients.reviews', $this->client))
        ->assertSessionHas('status', 'review-sent')->assertSessionHas('warning', fn ($w) => str_starts_with((string) $w, 'The e-mail to the approvers could not be sent ('));
    $review = Review::latest('id')->first();
    expect($this->dated->fresh()->state)->toBe(PostState::InReview)->and($review->posts)->toHaveCount(1);
    $this->actingAs($this->user)->get(route('clients.reviews', $this->client))->assertOk()->assertSee('could not be sent')->assertSee($review->url());
});

test('staff send posts for approval: they go in review and the approvers get a signed link', function () {
    $review = sendForApproval($this->client, $this->dated, $this->undated);

    expect($review->due_at->toDateTimeString())->toBe('2026-11-05 10:00:00')
        ->and($review->created_by)->toBe($this->user->id)
        ->and($this->dated->fresh()->state)->toBe(PostState::InReview)
        ->and($this->undated->fresh()->state)->toBe(PostState::InReview);
    Mail::assertSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'request' && $mail->hasTo('owner@example.com'));

    $this->get("/review/{$review->id}")->assertForbidden();
    $this->get($review->url())->assertOk()->assertSee('Almyra Beach Lounge')->assertSee('2 posts · 0 approved · 0 to rework · 2 waiting for you');
    $this->actingAs($this->user)->get(route('clients.reviews', $this->client))->assertOk()->assertSee('no answer yet');

    // a published post cannot be sent, and a client without approvers gets told
    $published = Post::factory()->create(['client_id' => $this->client->id, 'state' => PostState::Published]);
    $this->actingAs($this->user)->post(route('reviews.store', $this->client), ['posts' => [$published->id]])->assertSessionHasErrors('posts.0');
});

test('the client approves or requests changes from the link; approval schedules, the last answer closes the review', function () {
    $review = sendForApproval($this->client, $this->dated, $this->undated);
    $url = $review->url();

    $this->post($url, ['post_id' => $this->dated->id, 'decision' => 'approved', 'name' => 'Maria'])
        ->assertSessionHasNoErrors()->assertRedirect($url);
    expect($this->dated->fresh()->state)->toBe(PostState::Scheduled)
        ->and($review->fresh()->completed_at)->toBeNull();

    $this->post($url, ['post_id' => $this->undated->id, 'decision' => 'changes_requested', 'name' => 'Maria'])
        ->assertSessionHasErrors('comment');
    $this->post($url, ['post_id' => $this->undated->id, 'decision' => 'changes_requested', 'comment' => 'Use the sunset photo', 'name' => 'Maria'])
        ->assertSessionHasNoErrors();

    expect($this->undated->fresh()->state)->toBe(PostState::ChangesRequested)
        ->and($review->fresh()->completed_at)->not->toBeNull()
        ->and($review->posts()->find($this->undated->id)->pivot->comment)->toBe('Use the sunset photo');
    Mail::assertSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'completed' && $mail->hasTo($this->user->email));

    // decided posts cannot be decided again (a friendly line, ScanFixesPostsTest), and the answers show on both sides
    $this->post($url, ['post_id' => $this->dated->id, 'decision' => 'changes_requested', 'comment' => 'x', 'name' => 'Maria'])->assertSessionHasNoErrors()->assertSessionHas('notice');
    expect($this->dated->fresh()->state)->toBe(PostState::Scheduled);
    $this->get($url)->assertSee('All answered')->assertSee('Use the sunset photo');
    $this->actingAs($this->user)->get(route('posts.edit', $this->undated))->assertSee('Use the sunset photo');
});

test('reviews:remind reminds at half the SLA, escalates when overdue and auto-approves for clients who allow it', function () {
    $review = sendForApproval($this->client, $this->dated);

    $this->travelTo('2026-11-04 09:00:00');
    $this->artisan('reviews:remind');
    Mail::assertNotSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'reminder');

    $this->travelTo('2026-11-04 11:00:00');
    $this->artisan('reviews:remind');
    $this->artisan('reviews:remind');
    Mail::assertSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'reminder' && $mail->hasTo('owner@example.com'));
    expect(Mail::sent(ReviewMail::class)->where('kind', 'reminder'))->toHaveCount(1);

    $this->travelTo('2026-11-05 11:00:00');
    $this->artisan('reviews:remind');
    $this->artisan('reviews:remind');
    expect(Mail::sent(ReviewMail::class)->where('kind', 'overdue'))->toHaveCount(1)
        ->and($review->fresh()->escalated_at)->not->toBeNull()
        ->and($this->dated->fresh()->state)->toBe(PostState::InReview);

    $relaxed = Client::factory()->create();
    $relaxed->update(['profile' => array_replace_recursive($relaxed->profile, ['approval' => ['policy' => 'optional', 'auto_approve_on_timeout' => true]])]);
    $post = Post::factory()->create(['client_id' => $relaxed->id]);
    $auto = sendForApproval($relaxed, $post);

    $this->travelTo('2026-11-08 00:00:00');
    $this->artisan('reviews:remind');
    expect($post->fresh()->state)->toBe(PostState::Approved)
        ->and($auto->fresh()->completed_at)->not->toBeNull()
        ->and($auto->posts()->find($post->id)->pivot->decided_by)->toBe('auto');
});

test('the review page serves a post\'s media only through the signed link and only for its own posts', function () {
    Storage::fake(config('filesystems.default'));
    Storage::disk(config('filesystems.default'))->put('clients/x/inbox/a.jpg', 'jpg-bytes');
    $asset = Asset::factory()->create(['client_id' => $this->client->id, 'disk' => config('filesystems.default'), 'path' => 'clients/x/inbox/a.jpg']);
    $this->dated->assets()->attach($asset);
    $this->dated->variants()->create(['platform' => 'instagram', 'lang' => 'el', 'caption' => 'Ηλιοβασίλεμα στην Αλμύρα', 'hashtags' => ['almyra', '#chania']]);
    $stranger = Asset::factory()->create();
    $review = sendForApproval($this->client, $this->dated);

    $this->get($review->url())->assertOk()->assertSee("/review/{$review->id}/asset/{$asset->id}")->assertSee('Ηλιοβασίλεμα στην Αλμύρα')->assertSee('#almyra #chania');
    $this->get($review->assetUrl($asset))->assertOk();
    $this->get("/review/{$review->id}/asset/{$asset->id}")->assertForbidden();
    $this->get($review->assetUrl($stranger))->assertNotFound();
});

/** The real mailer against a closed port: what a broken mail setting on the server does. */
function breakTheMailServer(): void
{
    Mail::swap(new MailManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
}

function withoutApprovers(Client $client): Client
{
    $profile = $client->profile;
    $profile['approval']['approvers'] = [];
    $client->update(['profile' => $profile]);

    return $client;
}

test('a client without approvers still gets a review and its link: no e-mail goes out and the page says to send the link yourself', function () {
    withoutApprovers($this->client);

    $this->actingAs($this->user)->post(route('reviews.store', $this->client), ['posts' => [$this->dated->id]])
        ->assertSessionHasNoErrors()->assertRedirect(route('clients.reviews', $this->client))
        ->assertSessionHas('warning', 'Almyra Beach Lounge has no approver in the profile, so no e-mail went out. Copy the link on the Approvals page and send it to the client yourself.');
    $review = Review::latest('id')->first();

    expect($this->dated->fresh()->state)->toBe(PostState::InReview);
    Mail::assertNothingSent();
    $this->actingAs($this->user)->get(route('clients.reviews', $this->client))->assertOk()
        ->assertSee($review->url())->assertSee('approvers: none yet, so you send the link yourself.');
    $this->get($review->url())->assertOk()->assertSee('Almyra Beach Lounge');

    // the calendar list's bulk "send for approval" takes the same road
    $this->actingAs($this->user)->post(route('posts.bulk'), ['action' => 'review', 'posts' => [$this->undated->id]])->assertSessionHasNoErrors();
    expect($this->undated->fresh()->state)->toBe(PostState::InReview);
});

test('the client\'s answer goes through when the mail server is down: no error page, the post moves on and the review closes', function () {
    $review = sendForApproval($this->client, $this->dated);
    breakTheMailServer(); // the "all answered" mail to the agency fails

    $this->post($review->url(), ['post_id' => $this->dated->id, 'decision' => 'approved', 'name' => 'Maria'])
        ->assertSessionHasNoErrors()->assertRedirect($review->url());

    expect($this->dated->fresh()->state)->toBe(PostState::Scheduled)
        ->and($review->fresh()->completed_at)->not->toBeNull()
        ->and($review->posts()->first()->pivot->decided_by)->toBe('Maria');
    $this->get($review->url())->assertOk();
});

test('reviews:remind carries on past a client without approvers and a mail server that is down', function () {
    $other = withoutApprovers(Client::factory()->create());
    $silent = sendForApproval($other, Post::factory()->create(['client_id' => $other->id]));
    $mailed = sendForApproval($this->client, $this->dated);
    breakTheMailServer();

    $this->travel(30)->hours(); // past half of the 48 h: reminders (one to nobody, one that fails)
    $this->artisan('reviews:remind')->assertSuccessful();
    expect($silent->fresh()->reminded_at)->not->toBeNull()->and($mailed->fresh()->reminded_at)->not->toBeNull();

    $this->travel(30)->hours(); // overdue: the escalation mail fails too, and the run still ends well
    $this->artisan('reviews:remind')->assertSuccessful();
    expect($silent->fresh()->escalated_at)->not->toBeNull()->and($mailed->fresh()->escalated_at)->not->toBeNull();
});
