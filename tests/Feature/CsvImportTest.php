<?php

use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->client = Client::factory()->create(['slug' => 'almyra', 'name' => 'Almyra', 'code' => 'ALMYRA']);
    $this->staff = User::factory()->create();
});

test('the template has the client\'s columns and a filled CSV becomes Drafts on the calendar', function () {
    $this->actingAs($this->staff)->get(route('posts.import.template', $this->client))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee('date,time,platforms,format,pillar,caption_el,caption_en,hashtags,alt_text,first_comment,brief');
    $pillar = array_key_first($this->client->profile['pillars']);
    $csv = "\xEF\xBB\xBFdate;time;platforms;format;pillar;caption_el;caption_en;hashtags;alt_text;first_comment;brief\n"
        ."2027-05-12;18:00;instagram, facebook;feed;$pillar;Καλημέρα;Good morning;#chania #crete;The terrace;Book now;Sunset\n"
        ."2027-05-13;;linkedin;feed;;Νέο μενού;New menu;;;;\n"
        .";;;;;;;;;;\n";

    $this->actingAs($this->staff)->post(route('posts.import', $this->client), ['csv' => UploadedFile::fake()->createWithContent('posts.csv', $csv)])
        ->assertRedirect(route('calendar', ['client' => 'almyra']))->assertSessionHas('status', '2 drafts imported');

    $feed = Post::where('name', 'ALMYRA_20270512_MULTI_FEED_'.strtoupper($pillar).'_EL-EN_v1')->firstOrFail();
    expect($feed->state->value)->toBe('draft')
        ->and($feed->scheduled_at->toDateTimeString())->toBe('2027-05-12 18:00:00')
        ->and($feed->settings)->toBe(['first_comment' => 'Book now'])
        ->and($feed->brief)->toBe('Sunset')
        ->and($feed->created_by)->toBe($this->staff->id)
        ->and($feed->variants->map(fn ($v) => [$v->platform, $v->lang, $v->caption, $v->alt_text, $v->hashtags])->all())->toBe([
            ['instagram', 'el', 'Καλημέρα', 'The terrace', ['#chania', '#crete']], ['instagram', 'en', 'Good morning', 'The terrace', ['#chania', '#crete']],
            ['facebook', 'el', 'Καλημέρα', 'The terrace', ['#chania', '#crete']], ['facebook', 'en', 'Good morning', 'The terrace', ['#chania', '#crete']],
        ]);
    $li = Post::where('name', 'ALMYRA_20270513_LI_FEED_MISC_EL-EN_v1')->firstOrFail();
    expect($li->scheduled_at->format('H:i'))->toBe('10:00')->and($li->settings)->toBeNull()->and(Post::count())->toBe(2);
});

test('a wrong row stops the whole import and names the row', function () {
    $csv = "date,platforms,format\n2027-05-12,instagram,feed\nnot a date,twitter,poster\n";

    $this->actingAs($this->staff)->post(route('posts.import', $this->client), ['csv' => UploadedFile::fake()->createWithContent('posts.csv', $csv)])
        ->assertRedirect(route('clients.planner', $this->client))
        ->assertSessionHasErrors(['csv' => 'Row 3: platforms must be some of instagram, facebook, tiktok, linkedin.']);
    expect(session('errors')->get('csv'))->toHaveCount(3)->and(Post::count())->toBe(0);

    $this->actingAs($this->staff)->post(route('posts.import', $this->client), ['csv' => UploadedFile::fake()->createWithContent('posts.csv', "title\nx\n")])
        ->assertSessionHasErrors(['csv' => "The first row must name the columns; 'date' is missing. Download the template."]);
});
