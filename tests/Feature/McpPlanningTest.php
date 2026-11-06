<?php

use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\SavePlan;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Posts\PostState;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA', 'slug' => 'almyra']);
    $this->pillar = array_key_first($this->client->profile['pillars']);
});

test('get-planning-brief hands Claude the profile, the quota, the window and the recent posts, and asks it to research the trends itself', function () {
    Post::factory()->create(['client_id' => $this->client->id, 'scheduled_at' => now()->subDays(3), 'brief' => "Sunset cocktails\nGolden hour.\nTrend: Golden hour reels. Why now: x"]);

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 10, 'from' => '2027-05-01', 'days' => 31])->assertOk()
        ->assertSee(['"window":{"start":"2027-05-01","end":"2027-05-31"}', '"count":10', '"platforms":["instagram","facebook","tiktok","linkedin"]', '"quota":{"'])
        ->assertSee(['Almyra', '2027-05-01, label: Season opening', '2027-05-01 Μεγάλο Σάββατο (Ανάσταση) · Πρωτομαγιά', 'Sunset cocktails (trend: Golden hour reels)', 'Research it yourself', 'in Greek', 'instagram 2200', '#chania', 'save-plan']);

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 3, 'instruction' => 'Only the full moon party'])->assertOk()->assertSee('"quota":[]');
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 99])->assertHasErrors(['between 1 and 30']);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'platforms' => ['pinterest']])->assertHasErrors(['subset of the client accounts']);
});

test('save-plan files a plan without pillars when the profile has none', function () {
    $this->client->update(['profile' => ['pillars' => []] + $this->client->profile]);

    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'feed', 'title' => 'No pillar', 'angle' => 'Topic from About.']]])
        ->assertOk()->assertSee('"drafts":1');

    expect(Post::where('name', 'ALMYRA_20270512_IG_FEED_MISC_EL-EN_v1')->firstOrFail()->pillar)->toBeNull();
});

test('save-plan files the posts as Drafts with captions, the trend brief and the review sheet, publishing nothing', function () {
    $this->client->update(['profile' => ['slots' => ['tue 18:00']] + $this->client->profile]);
    $posts = [
        ['date' => '2027-05-12', 'platforms' => ['instagram', 'facebook'], 'format' => 'reel', 'pillar' => $this->pillar, 'title' => 'Sunset cocktails', 'angle' => 'Golden hour at the bar.', 'trend_signal' => 'Golden hour reels', 'why_now' => 'Trending', 'asset_brief' => 'Shoot the terrace', 'caption_brief' => 'Mention happy hour',
            'captions' => ['el' => ['instagram' => 'Κοκτέιλ στο ηλιοβασίλεμα', 'facebook' => 'Κοκτέιλ FB'], 'en' => ['instagram' => 'Sunset cocktails', 'facebook' => ''], 'hashtags' => ['#chania'], 'alt_text' => ['el' => 'Μπαρ', 'en' => 'Bar'], 'design' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset'], 'notes_for_reviewer' => 'Check the hours']],
        ['date' => '2027-05-20', 'platforms' => ['linkedin'], 'format' => 'feed', 'pillar' => $this->pillar, 'title' => 'Meet the team', 'angle' => 'The people behind the bar.'],
    ];

    $response = SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'trend_brief' => '# May trends', 'posts' => $posts])->assertOk()->assertSee('"drafts":2');

    $reel = Post::where('name', 'ALMYRA_20270518_MULTI_REEL_'.strtoupper($this->pillar).'_EL-EN_v1')->firstOrFail(); // the next Tuesday slot from the 12th
    $response->assertSee(route('posts.edit', $reel));
    expect($reel->state)->toBe(PostState::Draft)->and($reel->scheduled_at->format('Y-m-d H:i'))->toBe('2027-05-18 18:00')
        ->and($reel->brief)->toContain('Τάση: Golden hour reels')->and($reel->settings['design']['en'])->toBe('Sunset')
        ->and($reel->variants->map(fn ($v) => "{$v->platform}.{$v->lang}")->all())->toBe(['instagram.el', 'instagram.en', 'facebook.el']) // the empty English Facebook caption is skipped
        ->and($reel->variants->first()->hashtags)->toBe(['#chania']);
    $li = Post::where('platforms', 'like', '%linkedin%')->firstOrFail();
    expect($li->variants)->toHaveCount(0)->and($li->brief)->toStartWith("Meet the team\nThe people behind the bar.");

    $reports = Report::orderBy('id')->get();
    expect($reports->pluck('kind')->all())->toBe(['trend_brief', 'plan'])
        ->and($reports[0]->body)->toBe('# May trends')
        ->and($reports[1]->title)->toBe('Πλάνο περιεχομένου 12-05-2027 έως 25-05-2027') // the client's first language, DD-MM-YYYY, up to the day the LinkedIn post landed on
        ->and($reports[1]->body)->toContain('## 1. Sunset cocktails (18-05-2027 18:00)')->toContain('Σχεδιάστηκε για 12-05-2027')->toContain('Οδηγίες λεζάντας: Mention happy hour')->toContain('Σημειώσεις για τον ελεγκτή: Check the hours')->toContain('# May trends')
        ->and($reports[1]->meta['posts'][1]['name'])->toBe($li->name);
    $response->assertSee('"review_sheet":"'.route('reports.show', $reports[1]).'"');

    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [['date' => 'soon', 'platforms' => ['pinterest'], 'format' => 'poster', 'pillar' => 'nope', 'title' => 'x', 'angle' => 'y']]])
        ->assertHasErrors(['posts.0.date', 'posts.0.platforms.0', 'posts.0.format', 'posts.0.pillar']);
    expect(Post::count())->toBe(2);
});

test('the comment and numbers tools exist only while publishing is on', function () {
    SocialOpsServer::tools()->assertRegistered([GetPlanningBrief::class, SavePlan::class, ListComments::class]);
    config()->set('features.publishing', false);
    SocialOpsServer::tools()->assertRegistered([GetPlanningBrief::class, SavePlan::class])->assertNotRegistered(ListComments::class);
});
