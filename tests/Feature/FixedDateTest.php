<?php

use App\Drafting\CaptionDrafter;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\SavePlan;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Planning\ContentPlanner;
use App\Planning\PlanRequest;
use App\Planning\PlanWriter;
use App\Planning\TrendResearcher;
use Carbon\CarbonImmutable;

// Posts tied to a day keep their day (D24). 2026-10-28, Ohi Day, is a Wednesday: with slots on Tuesday, Thursday and
// Saturday a post planned there used to land on the Thursday.
beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA', 'slug' => 'almyra']);
    $this->slots = fn (array $slots) => $this->client->update(['profile' => ['slots' => $slots] + $this->client->profile]);
    ($this->slots)(['tue 19:00', 'thu 19:00', 'sat 19:00']);
    $this->post = fn (array $post) => $post + ['date' => '2026-10-28', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => 'place', 'title' => 'Sunset cocktails', 'angle' => 'Golden hour at the bar.'];
    $this->save = fn (array $posts) => SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => array_map($this->post, $posts)])->assertOk();
});

test('a post about its day keeps the day at the usual time; the others still move to the next free slot', function () {
    ($this->save)([['title' => 'Ohi Day parade', 'fixed_date' => true], ['fixed_date' => false]])
        ->assertSee(['"date":"2026-10-28","time":"19:00","fixed_date":true', '"date":"2026-10-29","time":"19:00","fixed_date":false', '"planned_date":"2026-10-28"']);

    [$parade, $sunset] = Post::orderBy('id')->get()->all();
    expect($parade->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-28 19:00')->and($parade->name)->toBe('ALMYRA_20261028_IG_FEED_PLACE_EL-EN_v1')
        ->and($sunset->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-29 19:00'); // the Thursday slot, as before

    $sheet = Report::where('kind', 'plan')->sole();
    expect($sheet->body)->toContain("## 1. Ohi Day parade (28-10-2026 19:00)\n\n- Μένει στην ημέρα του.\n")->toContain("## 2. Sunset cocktails (29-10-2026 19:00)\n\n- Σχεδιάστηκε για 28-10-2026")
        ->and($sheet->meta['posts'][0]['fixed_date'])->toBeTrue()->and($sheet->meta['posts'][0])->not->toHaveKey('planned_date')
        ->and($sheet->meta['posts'][1]['fixed_date'])->toBeFalse()->and($sheet->meta['posts'][1]['planned_date'])->toBe('2026-10-28');
});

test('the usual time is that weekday\'s slot, else the earliest slot, else 10:00, and an hour later on a clash', function () {
    ($this->slots)(['tue 19:00', 'thu 20:00', 'sat 19:00']);
    ($this->save)([['fixed_date' => true], ['date' => '2026-10-29', 'fixed_date' => true]])
        ->assertSee(['"date":"2026-10-28","time":"19:00"', '"date":"2026-10-29","time":"20:00"']); // Wednesday has no slot: the earliest slot time; Thursday has its own

    Post::factory()->create(['client_id' => $this->client->id, 'scheduled_at' => '2026-11-04 19:00']);
    ($this->save)([['date' => '2026-11-04', 'fixed_date' => true]])->assertSee('"date":"2026-11-04","time":"20:00"');

    foreach (['19:00', '20:00', '21:00', '22:00'] as $time) {
        Post::factory()->create(['client_id' => $this->client->id, 'scheduled_at' => "2026-11-11 {$time}"]);
    }
    ($this->save)([['date' => '2026-11-11', 'fixed_date' => true]])->assertSee('"date":"2026-11-11","time":"22:00"'); // three moves, then the clash is kept: the right day beats the wrong one

    ($this->slots)([]);
    ($this->save)([['date' => '2026-11-18', 'fixed_date' => true]])->assertSee('"date":"2026-11-18","time":"10:00"');
});

test('without the flag, a post planned on a holiday it talks about keeps the day; any other post on that day moves as before', function () {
    ($this->save)([
        ['title' => 'Παρέλαση στο λιμάνι', 'why_now' => 'Επέτειος του Όχι (Ohi Day): the parade passes the harbour.'],
        ['why_now' => 'Golden hour comes early now.'],
    ])->assertSee(['"date":"2026-10-28","time":"19:00","fixed_date":true', '"date":"2026-10-29","time":"19:00","fixed_date":false', '"planned_date":"2026-10-28"']);
    expect(Report::where('kind', 'plan')->sole()->body)->toContain('Μένει στην ημέρα του.')->toContain('Σχεδιάστηκε για 28-10-2026');

    $post = $this->post;
    expect(PlanWriter::keepsDay($this->client, $post(['why_now' => 'ΕΠΕΤΕΙΟΣ ΤΟΥ ΟΧΙ'])))->toBeTrue() // case and accents aside
        ->and(PlanWriter::keepsDay($this->client, $post(['title' => 'Όχι στα πλαστικά'])))->toBeFalse() // three letters do not count
        ->and(PlanWriter::keepsDay($this->client, $post(['date' => '2026-11-04', 'why_now' => 'Επέτειος του Όχι'])))->toBeFalse() // not a holiday: nothing to pin to
        ->and(PlanWriter::keepsDay($this->client, $post(['date' => '2026-10-16', 'why_now' => 'Παγκόσμια ημέρα για το ψωμί μας'])))->toBeFalse() // World Food Day: the words every awareness day shares do not count
        ->and(PlanWriter::keepsDay($this->client, $post(['date' => '2026-10-16', 'why_now' => 'World Bread Day: our sourdough.'])))->toBeTrue()
        ->and(PlanWriter::keepsDay($this->client, $post(['fixed_date' => false, 'why_now' => 'Επέτειος του Όχι'])))->toBeFalse(); // the flag, when sent, decides
});

test('the planner asks Claude for the flag as an optional boolean and keeps it on every post; the brief names it', function () {
    $request = new PlanRequest($this->client->toProfileArray(), 2, ['instagram'], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-11-08'));
    $post = ['date' => '2026-10-28', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => 'place', 'title' => 'x', 'angle' => 'y', 'trend_signal' => null, 'why_now' => 'z', 'asset_brief' => 'a', 'caption_brief' => 'b'];
    $planner = new ContentPlanner(fakeClaude(json_encode(['posts' => [$post + ['fixed_date' => true], $post]])));

    expect(array_column($planner->plan($request, '# brief')['posts'], 'fixed_date'))->toBe([true, false]);
    $items = json_decode((string) claudeRequests()[0]['request']->getBody(), true)['output_config']['format']['schema']['properties']['posts']['items'];
    expect($items['properties']['fixed_date'])->toBe(['type' => 'boolean'])->and($items['required'])->not->toContain('fixed_date')
        ->and(file_get_contents(base_path('prompts/content-plan.md')))->toContain('fixed_date: true');

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 2])->assertOk()->assertSee('fixed_date (true when the post is about that specific day');
});

test('content:plan keeps a flagged post on its day too', function () {
    $post = ['date' => '2026-10-28', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => 'place', 'title' => 'Ohi Day parade', 'angle' => 'The parade.', 'trend_signal' => null, 'why_now' => 'Ohi Day.', 'asset_brief' => 'Shoot the parade.', 'caption_brief' => 'Mention the time.', 'fixed_date' => true];
    $this->mock(TrendResearcher::class)->shouldReceive('brief')->once()->andReturn('# Trend brief');
    $this->mock(ContentPlanner::class)->shouldReceive('plan')->once()->andReturn(['posts' => [$post], 'warnings' => []]);
    $this->mock(CaptionDrafter::class)->shouldReceive('draft')->once()->andReturn(['el' => ['instagram' => 'Παρέλαση'], 'en' => ['instagram' => 'Parade'], 'hashtags' => [], 'alt_text' => [], 'notes_for_reviewer' => '']);

    $this->artisan('content:plan almyra --posts=1 --from=2026-10-26 --days=14')->assertSuccessful();
    expect(Post::sole()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-10-28 19:00')
        ->and(Report::where('kind', 'plan')->sole()->body)->toContain('Μένει στην ημέρα του.');
});
