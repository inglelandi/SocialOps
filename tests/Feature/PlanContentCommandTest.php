<?php

use App\Drafting\CaptionDrafter;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Planning\ContentPlanner;
use App\Planning\TrendResearcher;
use App\Posts\PostState;

it('creates Draft posts with captions per platform and language, plus a review sheet, publishing nothing', function () {
    $client = Client::factory()->create(['slug' => 'almyra', 'code' => 'ALMYRA']);
    $client->update(['profile' => ['slots' => ['wed 18:00', 'fri 10:00']] + $client->profile]); // 2027-05-12 is a Wednesday
    $post = ['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'reel', 'pillar' => 'food_drink', 'title' => 'Sunset cocktails',
        'angle' => 'Golden hour at the bar.', 'trend_signal' => 'Golden hour reels', 'why_now' => 'Trending this month.', 'asset_brief' => 'Shoot at 20:00.', 'caption_brief' => 'Soft CTA.'];
    $captions = ['el' => ['instagram' => 'Κοκτέιλ στο ηλιοβασίλεμα'], 'en' => ['instagram' => 'Sunset cocktails'], 'hashtags' => ['#chania'], 'alt_text' => ['el' => 'α', 'en' => 'a'], 'notes_for_reviewer' => ''];

    $this->mock(TrendResearcher::class)->shouldReceive('brief')->once()->andReturn('# Trend brief');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->once()->andReturn(['posts' => [$post, $post], 'warnings' => ['Pillar place: planned 0, quota 1']]);
    $this->mock(CaptionDrafter::class)->shouldReceive('draft')->twice()->andReturn($captions);

    $this->artisan('content:plan almyra --posts=2 --from=2027-05-10')
        ->expectsOutputToContain('ALMYRA_20270514_IG_REEL_FOODDRINK_EL-EN_v1')
        ->expectsOutputToContain('Pillar place: planned 0, quota 1')
        ->assertSuccessful();

    expect(Post::count())->toBe(2);
    $draft = Post::where('name', 'ALMYRA_20270512_IG_REEL_FOODDRINK_EL-EN_v1')->firstOrFail();
    expect($draft->state)->toBe(PostState::Draft)
        ->and($draft->scheduled_at->format('Y-m-d H:i'))->toBe('2027-05-12 18:00')
        ->and(Post::where('name', 'ALMYRA_20270514_IG_REEL_FOODDRINK_EL-EN_v1')->value('scheduled_at')->format('Y-m-d H:i'))->toBe('2027-05-14 10:00') // the second post takes the next free slot
        ->and($draft->brief)->toContain('Τάση: Golden hour reels')
        ->and($draft->variants->map(fn ($v) => [$v->platform, $v->lang, $v->caption, $v->alt_text, $v->hashtags])->all())->toBe([
            ['instagram', 'el', 'Κοκτέιλ στο ηλιοβασίλεμα', 'α', ['#chania']],
            ['instagram', 'en', 'Sunset cocktails', 'a', ['#chania']],
        ]);

    $reports = Report::orderBy('id')->get();
    expect($reports->pluck('kind')->all())->toBe(['trend_brief', 'plan'])
        ->and($reports[0]->body)->toBe('# Trend brief')
        ->and($reports[1]->title)->toBe('Πλάνο περιεχομένου 10-05-2027 έως 23-05-2027')
        ->and($reports[1]->body)->toContain('## 1. Sunset cocktails (12-05-2027 18:00)')->toContain('## 2. Sunset cocktails (14-05-2027 10:00)')->toContain('Κάθε λεζάντα θέλει έλεγχο από άνθρωπο')
        ->and($reports[1]->meta['posts'][0]['captions']['el']['instagram'])->toBe('Κοκτέιλ στο ηλιοβασίλεμα');
});

it('follows an instruction, reuses a trend brief from the last 7 days, and prints the post link', function () {
    $client = Client::factory()->create(['slug' => 'almyra', 'code' => 'ALMYRA']);
    $client->reports()->create(['kind' => 'trend_brief', 'title' => 'Old', 'body' => '# Old brief'])->forceFill(['created_at' => now()->subDays(8)])->save();
    $recent = $client->reports()->create(['kind' => 'trend_brief', 'title' => 'Recent', 'body' => '# Recent brief']);
    $post = ['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'reel', 'pillar' => 'offers', 'title' => 'Sunset cocktails',
        'angle' => 'Golden hour at the bar.', 'trend_signal' => 'Golden hour reels', 'why_now' => 'Trending this month.', 'asset_brief' => 'Shoot at 20:00.', 'caption_brief' => 'Soft CTA.'];
    $captions = ['el' => ['instagram' => 'Κοκτέιλ'], 'en' => ['instagram' => 'Cocktails'], 'hashtags' => ['#chania'], 'alt_text' => ['el' => 'α', 'en' => 'a'], 'notes_for_reviewer' => ''];

    $research = $this->mock(TrendResearcher::class);
    $research->shouldNotReceive('brief');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->twice()
        ->withArgs(fn ($request, $brief) => $request->instruction === 'Sunset cocktails this weekend' && $request->count === 1 && in_array($brief, ['# Recent brief', '# New brief']))
        ->andReturn(['posts' => [$post], 'warnings' => []]);
    $this->mock(CaptionDrafter::class)->shouldReceive('draft')->twice()->andReturn($captions);

    $this->artisan('content:plan almyra --posts=1 --from=2027-05-12 --days=1 --latest-brief --instruction="Sunset cocktails this weekend"')
        ->expectsOutputToContain('Post: '.config('app.url').'/posts/')
        ->assertSuccessful();
    expect(Post::count())->toBe(1)->and(Report::where('kind', 'trend_brief')->count())->toBe(2); // the recent brief was reused, none written

    // older than 7 days: researched again and saved
    $recent->delete();
    $this->mock(TrendResearcher::class)->shouldReceive('brief')->once()->andReturn('# New brief');
    $this->artisan('content:plan almyra --posts=1 --from=2027-05-12 --days=1 --latest-brief --instruction="Sunset cocktails this weekend"')->assertSuccessful();
    expect(Report::where('kind', 'trend_brief')->latest('id')->value('body'))->toBe('# New brief');
});

it('uses a supplied brief instead of researching, and tells the planner the recent topics', function () {
    $client = Client::factory()->create(['slug' => 'almyra']);
    Post::factory()->create(['client_id' => $client->id, 'scheduled_at' => now()->subDays(3), 'brief' => "Sunset cocktails\nGolden hour at the bar.\nTrend: Golden hour reels. Why now: trending."]);
    Post::factory()->create(['client_id' => $client->id, 'scheduled_at' => now()->subDays(200), 'brief' => 'Too old to matter']);
    $brief = tempnam(sys_get_temp_dir(), 'brief');
    file_put_contents($brief, '# Pasted /last30days brief');

    $this->mock(TrendResearcher::class)->shouldNotReceive('brief');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->once()->withArgs(fn ($request, $text, $recent) => $text === '# Pasted /last30days brief' && $recent === ['Sunset cocktails (trend: Golden hour reels)'])->andReturn(['posts' => [], 'warnings' => []]);

    $this->artisan("content:plan almyra --posts=1 --brief={$brief}")->assertSuccessful();
    expect(Report::pluck('kind')->all())->toBe(['plan']);
});
