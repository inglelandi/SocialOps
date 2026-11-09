<?php

// Regression tests for the planning, Claude queue, MCP, API and import/export findings of the scan of 2026-11-06.

use App\Clients\ClientProfile;
use App\Http\RateLimits;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetCaptionBrief;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\ListClients;
use App\Mcp\Tools\ListPosts;
use App\Mcp\Tools\SavePlan;
use App\Mcp\Tools\UpdatePost;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Planning\ContentPlanner;
use App\Planning\PlanRequest;
use App\Posts\CsvImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA', 'slug' => 'almyra']);
    $this->pillar = array_key_first($this->client->profile['pillars']);
    $this->plan = fn (array $post) => $post + ['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $this->pillar, 'title' => 'Sunset', 'angle' => 'Golden hour.'];
});

/** The document.xml of a Word download. */
function scanWordXml(TestResponse $response): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('word/document.xml');
    unlink($path);

    return $xml;
}

test('the review sheet, the Word file and the calendar give a post the same day, also after it moved to a slot', function () {
    $this->client->update(['profile' => ['slots' => ['tue 18:00']] + $this->client->profile]);

    // the feast day falls on a Sunday; the Draft lands on the next Tuesday slot
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)(['date' => '2027-08-15', 'title' => 'Panagia'])]])
        ->assertOk()->assertSee(['"date":"2027-08-17"', '"time":"18:00"', '"planned_date":"2027-08-15"']);

    $post = Post::sole();
    $sheet = Report::where('kind', 'plan')->sole();
    expect($post->scheduled_at->format('Y-m-d H:i'))->toBe('2027-08-17 18:00')
        ->and($sheet->meta['posts'][0]['date'])->toBe('2027-08-17')
        ->and($sheet->title)->toBe('Πλάνο περιεχομένου 15-08-2027 έως 17-08-2027')
        ->and($sheet->body)->toContain('## 1. Panagia (17-08-2027 18:00)')->toContain('Σχεδιάστηκε για 15-08-2027')->not->toContain('2027-08-15');

    $staff = $this->actingAs(User::factory()->create());
    expect(scanWordXml($staff->get(route('reports.word', $sheet))->assertOk()))->toContain('17/8 · Panagia')->toContain('17/08 – Panagia');

    // the team moves it on the calendar later: the Word file follows the calendar
    $post->update(['scheduled_at' => '2027-08-20 12:00']);
    expect(scanWordXml($staff->get(route('reports.word', $sheet))))->toContain('20/8 · Panagia')->not->toContain('17/8 · Panagia');
});

test('the CSV import reads slash dates day first, as Greek Excel saves them, and ISO dates as they are', function () {
    expect(CsvImport::when('05/12/2026', '18:00')->format('Y-m-d H:i'))->toBe('2026-12-05 18:00')
        ->and(CsvImport::when('13/05/2027')->format('Y-m-d H:i'))->toBe('2027-05-13 10:00')
        ->and(CsvImport::when('5.12.2026')->format('Y-m-d'))->toBe('2026-12-05')
        ->and(CsvImport::when('05-12-26')->format('Y-m-d'))->toBe('2026-12-05')
        ->and(CsvImport::when('05/12/2026 21:30')->format('Y-m-d H:i'))->toBe('2026-12-05 21:30')
        ->and(CsvImport::when('2027-05-12', '18:00:00')->format('Y-m-d H:i'))->toBe('2027-05-12 18:00')
        ->and(CsvImport::when('2027/05/12')->format('Y-m-d'))->toBe('2027-05-12')
        ->and(CsvImport::when('31/02/2027'))->toBeNull()
        ->and(CsvImport::when('05/12/2026', '25:00'))->toBeNull()
        ->and(CsvImport::when('soon'))->toBeNull();

    $csv = "date;time;platforms;format\n05/12/2026;18:00;instagram;feed\n2027-05-12;;facebook;feed\n";
    expect(CsvImport::import($this->client, $csv))->toBe(['imported' => 2, 'errors' => []])
        ->and(Post::orderBy('id')->get()->map(fn ($p) => $p->scheduled_at->format('Y-m-d H:i'))->all())->toBe(['2026-12-05 18:00', '2027-05-12 10:00']);
    expect(CsvImport::import($this->client, "date,platforms,format\n31/02/2027,instagram,feed\n")['errors'][0])->toContain("Row 2: date '31/02/2027'");
});

test('a pillar name longer than the 40-character column is refused when the profile is saved', function () {
    $yaml = fn (array $pillars) => Yaml::dump(['pillars' => $pillars] + ClientProfile::parse(file_get_contents(base_path('config/clients/example.yaml'))), 4);
    $long = 'φαγητό_και_ποτά_στο_ηλιοβασίλεμα_της_παραλίας'; // 45 characters

    expect(fn () => ClientProfile::parse($yaml([$long => 60, 'place' => 40])))->toThrow(ValidationException::class, 'Pillar names are at most 40 characters: "φαγητό και ποτά στο ηλιοβασίλεμα της παραλίας" is longer.');
    expect(ClientProfile::parse($yaml([mb_substr($long, 0, 40) => 60, 'place' => 40]))['pillars'])->toHaveCount(2); // 40 is fine

    // the client form shows it with the other profile errors; nothing is saved
    $this->actingAs(User::factory()->create())->from(route('clients.edit', $this->client))
        ->patch(route('clients.update', $this->client), ['name' => 'Almyra', 'slug' => 'almyra', 'code' => 'ALMYRA', 'type' => 'beach_bar', 'location' => 'Chania', 'profile' => $yaml([$long => 100])])
        ->assertSessionHasErrors('profile');
    expect($this->client->fresh()->profile['pillars'])->not->toHaveKey($long);
});

test('a post whose pillar was renamed in the profile still gets its captions from Claude', function () {
    $post = Post::factory()->create(['client_id' => $this->client->id, 'pillar' => 'place', 'settings' => ['ask_claude' => true]]);
    $profile = $this->client->profile;
    $profile['pillars'] = ['venue' => $profile['pillars']['place']] + array_diff_key($profile['pillars'], ['place' => 1]);
    $this->client->update(['profile' => $profile]);

    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertSee('"post":'.$post->id);
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $post->id])->assertOk();
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'variants' => ['instagram' => ['el' => ['caption' => 'Γεια']]], 'settings' => ['ask_claude' => false]])->assertOk();

    $post->refresh();
    expect($post->pillar)->toBe('place')->and($post->variants->firstWhere('lang', 'el')->caption)->toBe('Γεια');
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertDontSee('"post":'.$post->id);
    // a pillar the profile never had is still refused
    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'pillar' => 'nope'])->assertHasErrors(['pillar']);
});

test('the Word file downloads when the client name has a slash', function () {
    $this->client->update(['name' => 'Bar/Restaurant Almyra\\Chania']);
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)([])]])->assertOk();

    $response = $this->actingAs(User::factory()->create())->get(route('reports.word', Report::where('kind', 'plan')->sole()))->assertOk();
    expect(rawurldecode($response->headers->get('Content-Disposition')))->toContain('filename="Bar-Restaurant Almyra-Chania - Anartiseis')->toContain('Bar-Restaurant Almyra-Chania - Αναρτήσεις Μαΐου 2027.docx');
});

test('save-plan refuses a caption over the post form\'s limit, naming the post, the platform and the limit, and files nothing', function () {
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [
        ($this->plan)(['title' => 'Fine', 'platforms' => ['facebook'], 'captions' => ['el' => ['facebook' => str_repeat('α', 3000)]]]),
        ($this->plan)(['title' => 'Too long', 'captions' => ['el' => ['instagram' => str_repeat('α', 2201)], 'alt_text' => ['en' => str_repeat('a', 1001)]]]),
    ]])->assertHasErrors([
        'Post 2 ("Too long", 2027-05-12): the Instagram caption in EL is 2201 characters, over the limit of 2200. Shorten it and call save-plan again.',
        'Post 2 ("Too long", 2027-05-12): the alt text in EN is 1001 characters, over the limit of 1000.',
    ]);
    expect(Post::count())->toBe(0)->and(Report::count())->toBe(0);

    // at the limit, with the browser's CRLF line breaks counted as one, it is filed and the Draft saves again
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)(['captions' => ['el' => ['instagram' => str_repeat('α', 2190)."\r\n".str_repeat('β', 9)]]])]])->assertOk();
    SocialOpsServer::tool(UpdatePost::class, ['post' => Post::sole()->id, 'brief' => 'Changed'])->assertOk();
});

test('the JSON API and the MCP endpoint allow 120 calls a minute per token and then answer 429 in their own protocol', function () {
    [, $token] = ApiToken::issue('Script', null, null);
    [, $other] = ApiToken::issue('Other', null, null);
    for ($i = 0; $i < RateLimits::PER_MINUTE; $i++) {
        $this->withToken($token)->getJson('/api/clients')->assertOk();
    }
    $this->withToken($token)->getJson('/api/clients')->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Too many requests: 120 calls a minute per token.'));
    $this->withToken($other)->getJson('/api/clients')->assertOk(); // each token its own minute

    $call = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'list-clients', 'arguments' => []]];
    $headers = ['Accept' => 'application/json, text/event-stream'];
    for ($i = 0; $i < RateLimits::PER_MINUTE; $i++) {
        $this->withToken($token)->postJson('/mcp', $call, $headers)->assertOk();
    }
    $this->withToken($token)->postJson('/mcp', $call, $headers)->assertStatus(429)->assertHeader('Retry-After')
        ->assertJsonPath('jsonrpc', '2.0')->assertJsonPath('id', 7)->assertJsonPath('error.code', -32000);

    $this->travel(61)->seconds();
    $this->withToken($token)->getJson('/api/clients')->assertOk();
});

test('the connector sign-up allows a few registrations and Allow pages per address, then 429', function () {
    file_exists(storage_path('oauth-private.key')) || Artisan::call('passport:keys');
    $register = fn (string $ip = '203.0.113.5') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);

    for ($i = 0; $i < RateLimits::REGISTER_PER_MINUTE; $i++) {
        $register()->assertCreated();
    }
    $register()->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error', 'temporarily_unavailable');
    $register('198.51.100.7')->assertCreated(); // another address is not affected
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk(); // discovery is not counted

    for ($i = 0; $i < RateLimits::AUTHORIZE_PER_MINUTE; $i++) {
        expect($this->get('/oauth/authorize?client_id=x')->getStatusCode())->not->toBe(429); // an unknown client: Passport's own error
    }
    $this->get('/oauth/authorize?client_id=x')->assertStatus(429);
});

test('the clients board shows a plan due today in plain text and turns it red the day after', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
    $this->client->update(['plan_due' => '2026-11-06']);
    $staff = $this->actingAs(User::factory()->create());

    $staff->get(route('clients.index'))->assertOk()->assertSee('<span class="">06-11-2026</span>', false);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 09:00'));
    $staff->get(route('clients.index'))->assertSee('<span class="text-red-600 font-medium">06-11-2026</span>', false);
});

test('the planning brief: no "up to 0" hashtags, a client without handles can be planned, Greek trend lines count as recent trends', function () {
    $profile = $this->client->profile;
    $profile['hashtags'] = ['always' => [], 'pool' => [], 'max_per_post' => 0];
    $profile['accounts'] = ClientProfile::handles([]);
    $this->client->update(['profile' => $profile]);
    Post::factory()->create(['client_id' => $this->client->id, 'scheduled_at' => now()->subDays(3), 'brief' => "Sunset cocktails\nGolden hour.\nΤάση: Golden hour reels. Γιατί τώρα: x"]);

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra', 'posts' => 4])->assertOk()
        ->assertSee(['"platforms":["instagram","facebook","tiktok","linkedin"]', 'Hashtags: none (the profile allows 0 per post).', 'Sunset cocktails (trend: Golden hour reels)'])
        ->assertDontSee(['Hashtags: up to 0', 'always ,']);
    expect(PlanRequest::plannable($this->client->profile))->toBe(ClientProfile::PLATFORMS);

    $profile['hashtags'] = ['always' => ['#chania'], 'pool' => [], 'max_per_post' => 0];
    $this->client->update(['profile' => $profile]);
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'almyra'])->assertOk()->assertSee('Hashtags: up to 1, always #chania.');
});

test('list-clients names the platforms of the profile, not the Canva or Drive rows', function () {
    $this->client->accounts()->create(['platform' => 'canva', 'external_id' => 'x', 'name' => 'Canva', 'access_token' => '']);
    $profile = $this->client->profile;
    $profile['accounts'] = ClientProfile::handles(['instagram' => ['@almyra'], 'facebook' => ['Almyra']]);
    $this->client->update(['profile' => $profile]);

    SocialOpsServer::tool(ListClients::class)->assertOk()->assertSee('"platforms":["instagram","facebook"]')->assertDontSee('canva');
});

test('a control character in a plan text does not break the Word file', function () {
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)(['angle' => "Golden\vhour\x01 at the bar"])]])->assertOk();

    $xml = scanWordXml($this->actingAs(User::factory()->create())->get(route('reports.word', Report::where('kind', 'plan')->sole())));
    expect(simplexml_load_string($xml))->not->toBeFalse()->and($xml)->toContain('Goldenhour at the bar');
});

test('the Word file is titled after the month most of its posts fall in', function () {
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)(['date' => '2026-11-28']), ($this->plan)(['date' => '2026-12-05']), ($this->plan)(['date' => '2026-12-12'])]])->assertOk();

    $response = $this->actingAs(User::factory()->create())->get(route('reports.word', Report::where('kind', 'plan')->sole()));
    expect(rawurldecode($response->headers->get('Content-Disposition')))->toContain('Almyra - Αναρτήσεις Δεκεμβρίου 2026.docx')
        ->and(scanWordXml($response))->toContain('Προτεινόμενες θεματολογίες Δεκεμβρίου για Almyra');
});

test('"to" in list-posts and the API takes the whole of its last day', function () {
    Post::factory()->create(['client_id' => $this->client->id, 'name' => 'LAST_DAY_POST', 'scheduled_at' => '2027-05-31 19:00']);
    Post::factory()->create(['client_id' => $this->client->id, 'name' => 'NEXT_DAY_POST', 'scheduled_at' => '2027-06-01 09:00']);

    SocialOpsServer::tool(ListPosts::class, ['client' => 'almyra', 'to' => '2027-05-31'])->assertOk()->assertSee('LAST_DAY_POST')->assertDontSee('NEXT_DAY_POST');
    [, $token] = ApiToken::issue('t', null, null);
    $this->withToken($token)->getJson("/api/clients/{$this->client->id}/posts?from=2027-05-01&to=2027-05-31")->assertOk()->assertSee('LAST_DAY_POST')->assertDontSee('NEXT_DAY_POST');
    $this->withToken($token)->getJson("/api/clients/{$this->client->id}/posts?to=2027-05-31 18:00")->assertOk()->assertDontSee('LAST_DAY_POST'); // a time is still exact
});

test('content:plan for a profile without pillars asks for a null pillar instead of an empty list', function () {
    $this->client->update(['profile' => ['pillars' => []] + $this->client->profile]);
    $request = new PlanRequest($this->client->toProfileArray(), 1, ['instagram'], CarbonImmutable::parse('2027-05-01'), CarbonImmutable::parse('2027-05-31'));
    $planner = new ContentPlanner(fakeClaude(json_encode(['posts' => [['date' => '2027-05-03', 'platforms' => ['instagram'], 'format' => 'reel', 'pillar' => null, 'title' => 'x', 'angle' => 'y', 'trend_signal' => null, 'why_now' => 'z', 'asset_brief' => 'a', 'caption_brief' => 'b']]])));

    expect($planner->plan($request, '# brief')['warnings'])->toBe([])
        ->and(json_decode((string) claudeRequests()[0]['request']->getBody(), true)['output_config']['format']['schema']['properties']['posts']['items']['properties']['pillar'])->toBe(['type' => 'null']);
});

test('a plan whose review sheet fails to file leaves no Drafts behind', function () {
    Report::creating(fn () => throw new RuntimeException('disk full'));

    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'posts' => [($this->plan)([])]])->assertHasErrors();
    expect(Post::count())->toBe(0)->and(Report::count())->toBe(0); // a retry files the batch once, not twice
});
