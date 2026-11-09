<?php

use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Posts\PostState;
use Illuminate\Support\Facades\Storage;

// Post today (/today): what goes out by hand today or tomorrow, the texts to paste and Mark published.

beforeEach(function () {
    $this->travelTo('2026-11-06 09:00:00');
    $this->staff = User::factory()->create();
    $this->client = Client::factory()->create(['slug' => 'almyra', 'code' => 'ALMYRA', 'name' => 'Almyra Beach']);
});

function dueAt(Client $client, string $at, PostState $state = PostState::Scheduled, array $attributes = []): Post
{
    return Post::factory()->for($client)->create(['state' => $state, 'scheduled_at' => $at] + $attributes);
}

/** The ids of the post cards on the page, in page order. */
function cards(string $html): array
{
    preg_match_all('/<article id="post-(\d+)"/', $html, $m);

    return array_map('intval', $m[1]);
}

test('today lists the approved and scheduled posts due today, soonest first, with last week\'s unmarked ones flagged late', function () {
    $evening = dueAt($this->client, '2026-11-06 18:00');
    $morning = dueAt($this->client, '2026-11-06 10:30', PostState::Approved);
    dueAt($this->client, '2026-11-06 11:00', PostState::Draft);
    dueAt($this->client, '2026-11-06 12:00', PostState::InReview);
    $tomorrow = dueAt($this->client, '2026-11-07 09:00');
    $yesterday = dueAt($this->client, '2026-11-05 20:00'); // never marked published
    dueAt($this->client, '2026-10-29 20:00'); // older than a week: left to the calendar
    dueAt($this->client, '2026-11-06 13:00')->moveToBin($this->staff->id);
    $done = dueAt($this->client, '2026-11-06 08:00', PostState::Published, ['published_at' => '2026-11-06 08:05']);

    $html = $this->actingAs($this->staff)->get(route('today'))->assertOk()
        ->assertSee('Late: due Thu 05-11-2026')->assertSee('Done today')->assertSee('marked at 08:05')
        ->getContent();
    expect(cards($html))->toBe([$yesterday->id, $morning->id, $evening->id])
        ->and($html)->toContain('data-post="'.$done->id.'"')->toContain('Almyra Beach');

    $html = $this->actingAs($this->staff)->get(route('today', ['day' => 'tomorrow']))->assertOk()->assertSee('Saturday 07-11-2026')->getContent();
    expect(cards($html))->toBe([$tomorrow->id]);

    $this->actingAs($this->staff)->get(route('today', ['day' => 'yesterday']))->assertSessionHasErrors('day');
});

test('the client filter narrows the list, and an empty day says so with a link to the calendar', function () {
    $other = Client::factory()->create(['slug' => 'blue-villas']);
    $mine = dueAt($this->client, '2026-11-06 10:00');
    $theirs = dueAt($other, '2026-11-06 11:00');

    expect(cards($this->actingAs($this->staff)->get(route('today'))->getContent()))->toBe([$mine->id, $theirs->id])
        ->and(cards($this->actingAs($this->staff)->get(route('today', ['client' => 'blue-villas']))->getContent()))->toBe([$theirs->id]);

    $this->actingAs($this->staff)->get(route('today', ['day' => 'tomorrow', 'client' => 'almyra']))->assertOk()
        ->assertSee('Nothing to post tomorrow.')->assertSee(e(route('calendar', ['date' => '2026-11-07', 'client' => 'almyra'])), false);
});

test('a limited login sees only its own clients on the page and in the navigation count', function () {
    $other = Client::factory()->create(['slug' => 'blue-villas']);
    $mine = dueAt($this->client, '2026-11-06 10:00');
    $theirs = dueAt($other, '2026-11-06 11:00');
    $limited = User::factory()->create(['client_ids' => [$this->client->id]]);

    $html = $this->actingAs($limited)->get(route('today'))->assertOk()->getContent();
    expect(cards($html))->toBe([$mine->id])->and($html)->not->toContain($theirs->name);
    expect(cards($this->actingAs($limited)->get(route('today', ['client' => 'blue-villas']))->getContent()))->toBe([$mine->id]); // another client's slug is ignored
    expect($this->actingAs($limited)->get(route('dashboard'))->getContent())->toMatch('~'.preg_quote(route('today'), '~').'"[^>]*>\s*(<svg[^>]*>.*?</svg>\s*)?Post today<span[^>]*>1</span>~s'); // the link's icon, then its text and count

    $this->actingAs($limited)->post(route('posts.transition', $theirs), ['state' => 'published', 'return' => route('today')])->assertForbidden();
    expect($theirs->fresh()->state)->toBe(PostState::Scheduled);
});

test('the copy texts are the ZIP and CSV texts: every language, the signature, the hashtags, placeholders filled and links tagged', function () {
    Storage::fake('local');
    $this->client->forceFill(['profile' => [
        'signatures' => ['instagram' => 'Κρατήσεις / Bookings: {{phone}}'],
        'fields' => ['phone' => '+30 28210 00000', 'booking' => 'https://almyra.gr/book'],
        'utm' => true,
    ] + $this->client->profile])->save();
    $post = dueAt($this->client, '2026-11-06 19:00', PostState::Scheduled, ['platforms' => ['instagram', 'facebook'], 'settings' => ['first_comment' => 'Book: {{booking}}']]);
    $post->variants()->create(['platform' => 'instagram', 'lang' => 'el', 'caption' => 'Ηλιοβασίλεμα στο "deck" & κοκτέιλ', 'hashtags' => ['chania'], 'alt_text' => 'Το deck']);
    $post->variants()->create(['platform' => 'instagram', 'lang' => 'en', 'caption' => "Sunset on the deck.\nBook {{booking}}", 'hashtags' => ['#crete']]);
    $post->variants()->create(['platform' => 'facebook', 'lang' => 'el', 'caption' => 'FB {{menu}}', 'hashtags' => []]);
    Storage::disk('local')->put('clients/test/inbox/photo.jpg', 'jpeg bytes');
    $post->assets()->attach($asset = Asset::factory()->for($this->client)->create(['name' => 'ALMYRA_photo.jpg']), ['position' => 0]);
    $post = $post->fresh();

    $instagram = $post->captionFor('instagram');
    expect($instagram)->toBe("Ηλιοβασίλεμα στο \"deck\" & κοκτέιλ\n\nSunset on the deck.\nBook https://almyra.gr/book?utm_source=instagram&utm_medium=social&utm_campaign={$post->name}\n\nΚρατήσεις / Bookings: +30 28210 00000\n\n#chania #crete");

    $html = $this->actingAs($this->staff)->get(route('today'))->assertOk()->getContent();
    $comment = "Book: https://almyra.gr/book?utm_source=instagram&utm_medium=social&utm_campaign={$post->name}";
    expect($html)->toContain('data-copy="'.e($instagram).'"')
        ->toContain('<div class="whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 text-[15px] leading-relaxed text-gray-900">'.e($instagram).'</div>') // shown as it is copied
        ->toContain('data-copy="'.e($post->pasteText('instagram', ['el'])).'"') // one language alone, with the signature and the hashtags
        ->toContain('data-copy="'.e($comment).'"')
        ->toContain('data-copy="Το deck"')
        ->toContain(route('assets.thumb', $asset))->toContain(route('assets.download', $asset))->toContain(route('posts.export', $post))
        ->toContain('The placeholder {{menu}} is not in the client profile'); // Facebook: fixed on the post before it is pasted

    $zipPath = $this->actingAs($this->staff)->get(route('posts.export', $post))->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($zipPath);
    expect($zip->getFromName('captions.txt'))->toContain("== Instagram ==\n{$instagram}\n\nAlt text: Το deck\n\nFirst comment:\n{$comment}\n");

    $csv = fopen('php://temp', 'r+');
    fwrite($csv, substr($this->actingAs($this->staff)->get(route('posts.csv', [$this->client, 'date' => '2026-11-01']))->getContent(), 3)); // after the BOM
    rewind($csv);
    $header = fgetcsv($csv, escape: '\\');
    $row = array_combine($header, fgetcsv($csv, escape: '\\'));
    expect($row['platform'])->toBe('instagram')
        ->and($row['first_comment'])->toBe($comment)
        ->and($instagram)->toContain($row['caption_el'])->toContain($row['caption_en'])->toContain($row['hashtags']);
});

test('Mark published comes back to the list, where the post waits under Done today; an approved post goes straight to published', function () {
    config()->set('features.publishing', false); // organize mode: the team posts by hand
    $scheduled = dueAt($this->client, '2026-11-06 10:00');
    $approved = dueAt($this->client, '2026-11-06 12:00', PostState::Approved);
    $here = route('today', ['client' => 'almyra']);

    $this->actingAs($this->staff)->get($here)->assertOk()->assertSee('Mark published')->assertSee('Did you post it in the app?');
    $this->actingAs($this->staff)->post(route('posts.transition', $scheduled), ['state' => 'published', 'return' => $here])->assertRedirect($here);
    expect($scheduled->fresh())->state->toBe(PostState::Published)->published_at->toEqual(now());

    $html = $this->actingAs($this->staff)->get($here)->assertOk()->getContent();
    expect(cards($html))->toBe([$approved->id])->and($html)->toContain('data-post="'.$scheduled->id.'"')->toContain('Done today');

    $this->actingAs($this->staff)->post(route('posts.transition', $approved), ['state' => 'published', 'return' => route('today')])->assertRedirect(route('today'));
    expect($approved->fresh()->state)->toBe(PostState::Published);
    $this->actingAs($this->staff)->get(route('today'))->assertSee('Nothing left to post today.');

    // a late one from yesterday, marked today, is done today too
    $late = dueAt($this->client, '2026-11-05 19:00');
    $this->actingAs($this->staff)->post(route('posts.transition', $late), ['state' => 'published', 'return' => route('today')]);
    $this->actingAs($this->staff)->get(route('today'))->assertSee('data-post="'.$late->id.'"', false)->assertSee('Thu 05-11-2026 19:00');
    $this->actingAs($this->staff)->get(route('today', ['day' => 'tomorrow']))->assertDontSee('data-post="'.$late->id.'"', false);

    // only the Today page is followed back; any other address goes to the post as before
    $next = dueAt($this->client, '2026-11-06 15:00');
    $this->actingAs($this->staff)->post(route('posts.transition', $next), ['state' => 'published', 'return' => 'https://example.com/today'])->assertRedirect(route('posts.edit', $next));
    $draft = dueAt($this->client, '2026-11-06 16:00', PostState::Draft);
    $this->actingAs($this->staff)->from($here)->post(route('posts.transition', $draft), ['state' => 'published', 'return' => $here])->assertRedirect($here)->assertSessionHasErrors('state');
});

test('the navigation and the dashboard lead to the page; guests are sent to the login', function () {
    dueAt($this->client, '2026-11-06 10:00');
    $this->get(route('today'))->assertRedirect('/login');

    $html = $this->actingAs($this->staff)->get(route('dashboard'))->assertOk()->assertSee('Post them by hand: open Post today')->getContent();
    expect(substr_count($html, 'href="'.route('today').'"'))->toBe(2);
});
