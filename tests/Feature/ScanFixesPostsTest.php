<?php

/* Fixes from the second scan of 2026-11-06 (posts, approvals, calendar): each test fails on the code before it. */

use App\Mail\ReviewMail;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\Review;
use App\Models\User;
use App\Posts\PostState;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
    $this->staff = User::factory()->create();
});

/** The client's captions and pages in English only, so the mails and the portal read in English. */
function englishClient(array $attributes = []): Client
{
    $client = Client::factory()->create($attributes);
    $client->forceFill(['profile' => ['languages' => ['en']] + $client->profile])->save();

    return $client;
}

test('a post sent again leaves the older open review: the old link refuses it kindly and the empty review closes once overdue', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $client = englishClient();
    $moved = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Draft, 'scheduled_at' => '2026-11-10 10:00']);
    $answered = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Draft]);

    $this->actingAs($this->staff)->post(route('reviews.store', $client), ['posts' => [$moved->id, $answered->id]])->assertSessionHasNoErrors();
    $old = Review::latest('id')->first();
    $this->post($old->url(), ['post_id' => $answered->id, 'decision' => 'changes_requested', 'comment' => 'Another photo', 'name' => 'Maria'])->assertSessionHasNoErrors();

    // the post is still undecided in the old review and goes out again: it now lives only in the new link
    $this->actingAs($this->staff)->post(route('reviews.store', $client), ['posts' => [$moved->id]])->assertSessionHasNoErrors();
    $new = Review::latest('id')->first();
    expect($old->posts()->pluck('posts.id')->all())->toBe([$answered->id]) // the answered one keeps its answer there
        ->and($old->pending()->count())->toBe(0)
        ->and($new->pending()->pluck('posts.id')->all())->toBe([$moved->id]);

    // the old tab still shows it: its answer changes nothing and gets a friendly line, not an error
    $this->followingRedirects()->post($old->url(), ['post_id' => $moved->id, 'decision' => 'changes_requested', 'comment' => 'No', 'name' => 'Maria'])
        ->assertOk()->assertSee('This post is no longer waiting for your answer on this page');
    expect($moved->fresh()->state)->toBe(PostState::InReview);
    $this->post($new->url(), ['post_id' => $moved->id, 'decision' => 'approved', 'name' => 'Maria'])->assertSessionHasNoErrors();
    expect($moved->fresh()->state)->toBe(PostState::Scheduled);

    // nothing left to decide in the old one: no reminder, closed by reviews:remind once overdue
    $this->travelTo('2026-11-04 12:00:00');
    $this->artisan('reviews:remind')->assertSuccessful();
    Mail::assertNotSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'reminder');
    expect($old->fresh()->completed_at)->toBeNull();
    $this->travelTo('2026-11-05 11:00:00');
    $this->artisan('reviews:remind')->assertSuccessful();
    expect($old->fresh()->completed_at)->not->toBeNull();
});

test('a page left open, a second approver or a double tap gets a friendly line instead of a validation error', function () {
    $client = englishClient();
    $post = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::InReview]);
    $review = $client->reviews()->create(['due_at' => now()->addDay()]);
    $review->posts()->attach($post->id);
    $this->post($review->url(), ['post_id' => $post->id, 'decision' => 'approved', 'name' => 'Maria'])->assertSessionHasNoErrors();

    foreach ([$post->id, 'all'] as $postId) {
        $this->post($review->url(), ['post_id' => $postId, 'decision' => 'changes_requested', 'comment' => 'x', 'name' => 'Nikos'])
            ->assertSessionHasNoErrors()->assertRedirect($review->url())->assertSessionHas('notice');
    }
    expect($post->fresh()->state)->toBe(PostState::Approved)
        ->and($review->posts()->first()->pivot->decided_by)->toBe('Maria');
});

test('Mark published asks first, on the post page as on Post today', function () {
    $this->travelTo('2026-11-06 09:00:00');
    $post = Post::factory()->create(['state' => PostState::Scheduled, 'scheduled_at' => '2026-11-06 12:00']);
    $confirm = "onsubmit=\"return confirm('Did you post it in the app? This marks it published.')\"";

    $this->actingAs($this->staff)->get(route('posts.edit', $post))->assertOk()->assertSee($confirm, false);
    $this->actingAs($this->staff)->get(route('today'))->assertOk()->assertSee($confirm, false);
});

test('a post marked published by hand can go back: Scheduled with a date, Approved without, never one that went out through an API', function () {
    config(['features.publishing' => false]);
    $this->travelTo('2026-11-06 12:00:00');
    $dated = Post::factory()->create(['state' => PostState::Scheduled, 'scheduled_at' => '2026-11-06 10:00']);

    $this->actingAs($this->staff)->post(route('posts.transition', $dated), ['state' => 'published'])->assertSessionHasNoErrors();
    expect($dated->fresh()->state)->toBe(PostState::Published);
    $this->actingAs($this->staff)->delete(route('posts.destroy', $dated))->assertStatus(422); // deleting a published post stays forbidden
    $this->actingAs($this->staff)->get(route('posts.edit', $dated))->assertOk()->assertSee('Not published after all')->assertDontSee('id="post-form"', false);
    foreach (['scheduled', 'in_review', 'draft'] as $elsewhere) { // only the way back, nowhere else
        $this->actingAs($this->staff)->post(route('posts.transition', $dated), ['state' => $elsewhere])->assertSessionHasErrors('state');
    }

    $this->actingAs($this->staff)->post(route('posts.transition', $dated), ['state' => 'approved'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'marked-not-published');
    expect($dated->fresh()->state)->toBe(PostState::Scheduled)->and($dated->fresh()->published_at)->toBeNull();
    $this->actingAs($this->staff)->get(route('posts.edit', $dated))->assertOk()->assertSee('id="post-form"', false)->assertDontSee('Not published after all');

    $undated = Post::factory()->create(['state' => PostState::Published, 'published_at' => now()]);
    $this->actingAs($this->staff)->post(route('posts.transition', $undated), ['state' => 'approved'])->assertSessionHasNoErrors();
    expect($undated->fresh()->state)->toBe(PostState::Approved);

    $api = Post::factory()->create(['state' => PostState::Published, 'scheduled_at' => '2026-11-06 10:00', 'external_ids' => ['facebook' => '1_2']]);
    $this->actingAs($this->staff)->get(route('posts.edit', $api))->assertOk()->assertDontSee('Not published after all');
    $this->actingAs($this->staff)->post(route('posts.transition', $api), ['state' => 'approved'])->assertSessionHasErrors('state');
    expect($api->fresh()->state)->toBe(PostState::Published)
        ->and(PostState::Published->next())->toBe([PostState::Withdrawn]); // the post page's own buttons are unchanged

    // with posting on, one whose time has passed waits Approved, so the queue does not publish it at once
    config(['features.publishing' => true]);
    $late = Post::factory()->create(['state' => PostState::Published, 'scheduled_at' => '2026-11-06 10:00']);
    $soon = Post::factory()->create(['state' => PostState::Published, 'scheduled_at' => '2026-11-07 10:00']);
    $late->transitionTo(PostState::Approved);
    $soon->transitionTo(PostState::Approved);
    expect($late->state)->toBe(PostState::Approved)->and($soon->state)->toBe(PostState::Scheduled);
});

test('a repeating post posted by hand drafts its next occurrence once, also after Not published after all', function () {
    config(['features.publishing' => false]);
    $this->travelTo('2026-11-06 12:00:00');
    $client = Client::factory()->create(['code' => 'ALMYRA']);
    $post = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Approved, 'scheduled_at' => '2026-11-06 10:00', 'pillar' => 'food_drink', 'settings' => ['repeat' => 'weekly', 'first_comment' => '#chania']]);
    $post->variants()->create(['platform' => 'instagram', 'lang' => 'el', 'caption' => 'Κάθε Παρασκευή', 'hashtags' => []]);

    // from Post today: the Approved post is scheduled on the way, then marked published
    $this->actingAs($this->staff)->followingRedirects()->post(route('posts.transition', $post), ['state' => 'published', 'return' => route('today')])
        ->assertOk()->assertSee('Next occurrence drafted:')->assertSee('ALMYRA_20261113_IG_FEED_FOODDRINK_EL-EN_v1');
    $next = Post::where('id', '!=', $post->id)->sole();
    expect($post->fresh()->state)->toBe(PostState::Published)
        ->and($next->state)->toBe(PostState::Draft)
        ->and($next->scheduled_at->format('Y-m-d H:i'))->toBe('2026-11-13 10:00')
        ->and($next->settings)->toBe(['repeat' => 'weekly', 'first_comment' => '#chania'])
        ->and($next->variants->pluck('caption')->all())->toBe(['Κάθε Παρασκευή']);

    // marked by mistake, taken back, marked again: still one next occurrence, and it is left alone on the way back
    $this->actingAs($this->staff)->post(route('posts.transition', $post), ['state' => 'approved'])->assertSessionHasNoErrors();
    expect($post->fresh()->state)->toBe(PostState::Scheduled)->and($next->fresh())->not->toBeNull();
    $this->actingAs($this->staff)->post(route('posts.transition', $post), ['state' => 'published'])->assertSessionHasNoErrors()->assertSessionMissing('repeat');
    expect(Post::count())->toBe(2)->and($post->fresh()->state)->toBe(PostState::Published);

    // a copy of the post made by hand repeats on its own
    expect($post->fresh()->duplicate()->settings)->toBe(['repeat' => 'weekly', 'first_comment' => '#chania']);
});

test('the reminder counts only the posts still waiting for an answer', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $client = englishClient(['name' => 'Almyra']);
    $posts = Post::factory()->count(3)->create(['client_id' => $client->id]);
    $this->actingAs($this->staff)->post(route('reviews.store', $client), ['posts' => $posts->pluck('id')->all()])->assertSessionHasNoErrors();
    $review = Review::latest('id')->first();
    foreach ($posts->take(2) as $post) {
        $this->post($review->url(), ['post_id' => $post->id, 'decision' => 'approved', 'name' => 'Maria'])->assertSessionHasNoErrors();
    }

    $this->travelTo('2026-11-04 11:00:00');
    $this->artisan('reviews:remind')->assertSuccessful();
    Mail::assertSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'reminder'
        && $mail->envelope()->subject === 'Reminder: 1 posts for Almyra are waiting for your approval'
        && str_contains($mail->render(), '1 posts for Almyra are ready for your approval'));
});

test('the export ZIP leaves out a media file gone from the disk and names it in captions.txt', function () {
    Storage::fake('local');
    $post = Post::factory()->create();
    Storage::disk('local')->put('clients/test/inbox/here.jpg', 'the photo');
    $here = Asset::factory()->create(['client_id' => $post->client_id, 'path' => 'clients/test/inbox/here.jpg', 'name' => 'HERE_v1.jpg']);
    $gone = Asset::factory()->create(['client_id' => $post->client_id, 'path' => 'clients/test/inbox/gone.jpg', 'name' => 'GONE_v1.jpg']);
    $post->assets()->sync([$gone->id => ['position' => 0], $here->id => ['position' => 1]]);
    $post->update(['settings' => ['design' => ['el' => 'Τίτλος']]]); // the burned-in image of a missing photo is skipped too

    $response = $this->actingAs($this->staff)->get(route('posts.export', $post))->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    expect($zip->getFromName('HERE_v1.jpg'))->toBe('the photo')
        ->and($zip->locateName('GONE_v1.jpg'))->toBeFalse()
        ->and($zip->getFromName('captions.txt'))->toContain('Missing, not in this ZIP (the file is gone from the server, upload it again): GONE_v1.jpg');
});

test('a client comment on the post page shows its time as DD-MM-YYYY HH:MM', function () {
    $this->travelTo('2026-11-06 11:41:20');
    $post = Post::factory()->create(['state' => PostState::InReview]);
    $review = $post->client->reviews()->create(['due_at' => now()->addDay()]);
    $review->posts()->attach($post->id);
    $review->decide($post, 'changes_requested', 'Use the sunset photo', 'Maria');

    $this->actingAs($this->staff)->get(route('posts.edit', $post))->assertOk()
        ->assertSee('Maria</span> (06-11-2026 11:41): Use the sunset photo', false)->assertDontSee('2026-11-06 11:41:20');
});

test('the live preview on the post form fills Greek placeholder names too', function () {
    $post = Post::factory()->create();

    $this->actingAs($this->staff)->get(route('posts.edit', $post))->assertOk()
        ->assertSee('const fill = s => s.replace(/\{\{\s*([\p{L}\p{N}_]+)\s*\}\}/gu', false)
        ->assertDontSee('([a-z0-9_]+)', false);
});

test('a client unknown to the login or a mangled date in the address is a 404 or ignored, not an error page', function () {
    $this->travelTo('2026-11-06 09:00:00');
    $client = Client::factory()->create(['slug' => 'almyra']);
    $hidden = Client::factory()->create(['slug' => 'hidden']);
    $limited = User::factory()->create(['client_ids' => [$client->id]]);

    $this->actingAs($this->staff)->get('/calendar?client=nobody')->assertNotFound();
    $this->actingAs($this->staff)->get('/calendar?client[]=almyra')->assertNotFound();
    $this->actingAs($limited)->get('/calendar?client=hidden')->assertNotFound();
    $this->actingAs($this->staff)->get('/calendar?client=almyra&date=abc')->assertOk()->assertSee('November 2026');
    $this->actingAs($this->staff)->get('/calendar?date[]=x&state[]=y&view=week')->assertOk();
    $this->actingAs($this->staff)->get("/clients/{$client->id}/posts/create?date=abc")->assertOk();
    $this->actingAs($this->staff)->get("/clients/{$client->id}/posts/create?date=2026-12-01")->assertOk()->assertSee('2026-12-01T10:00');
    $this->actingAs($this->staff)->get("/clients/{$client->id}/posts/export.csv?date=abc")->assertOk();
});

test('the client calendar on the 31st shows this month and the next, not three', function () {
    $this->travelTo('2026-10-31 09:00:00');
    $client = Client::factory()->create();
    Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Scheduled, 'scheduled_at' => '2026-11-30 10:00', 'name' => 'NOVEMBER_POST']);
    Post::factory()->create(['client_id' => $client->id, 'state' => PostState::Scheduled, 'scheduled_at' => '2026-12-05 10:00', 'name' => 'DECEMBER_POST']);

    $this->withSession(['locale' => 'en'])->get(URL::temporarySignedRoute('calendar.client', now()->addDay(), ['client' => $client->slug]))
        ->assertOk()->assertSee('Monday 30-11-2026')->assertDontSee('05-12-2026');
});

test('the review finished mail goes to staff in the app language, not in the language of the client who answered', function () {
    config(['app.default_locale' => 'en']);
    $client = Client::factory()->create(); // Greek first: the portal page runs in Greek
    $post = Post::factory()->create(['client_id' => $client->id, 'state' => PostState::InReview]);
    $review = $client->reviews()->create(['due_at' => now()->addDay(), 'created_by' => $this->staff->id]);
    $review->posts()->attach($post->id);

    $this->post($review->url(), ['post_id' => $post->id, 'decision' => 'approved', 'name' => 'Maria'])->assertSessionHasNoErrors();
    Mail::assertSent(ReviewMail::class, fn (ReviewMail $mail) => $mail->kind === 'completed' && $mail->locale === 'en');
});

test('the clients board shows a plan due today as due, not overdue', function () {
    $this->travelTo('2026-11-06 09:00:00');
    Client::factory()->create(['plan_due' => '2026-11-06']);
    Client::factory()->create(['plan_due' => '2026-11-05']);

    $this->actingAs($this->staff)->get(route('clients.index'))->assertOk()
        ->assertSee('<span class="">06-11-2026</span>', false)
        ->assertSee('<span class="text-red-600 font-medium">05-11-2026</span>', false);
});
