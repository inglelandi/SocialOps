<?php

use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\SavePlan;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;

/*
 * A plan's posts come with their captions (Panos, 2026-11-09: "when i go to a post… the fields are empty"): the worker had
 * filed twelve Drafts with briefs and no captions, because the plan job said "plan, then save-plan" and save-plan took a post
 * without captions in silence. Now the job and the brief ask for the captions, save-plan refuses captions keyed the wrong way
 * instead of dropping them, and a post that still arrives without any is queued as a caption job and named in the answer.
 */

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Dokos Home', 'code' => 'DOKOS', 'slug' => 'dokos']);
    $this->client->update(['profile' => ['languages' => ['el', 'en']] + $this->client->profile]);
    $this->pillar = array_key_first($this->client->profile['pillars']);
    $this->post = fn (array $extra = []) => $extra + ['date' => '2027-05-12', 'platforms' => ['instagram', 'facebook'], 'format' => 'feed', 'pillar' => $this->pillar, 'title' => 'Winter comfort', 'angle' => 'Warm throws for the sofa.'];
});

test('a post filed without any caption is queued as a caption job and named in the answer; one with captions is not', function () {
    $response = SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [
        ($this->post)(['title' => 'With captions', 'captions' => ['el' => ['instagram' => 'Ζεστά ριχτάρια', 'facebook' => 'Ζεστά ριχτάρια FB'], 'en' => ['instagram' => 'Warm throws']]]),
        ($this->post)(['title' => 'Without captions', 'date' => '2027-05-14']),
        ($this->post)(['title' => 'Empty captions', 'date' => '2027-05-16', 'captions' => ['el' => ['instagram' => '  ', 'facebook' => ''], 'hashtags' => ['#chania']]]),
    ]])->assertOk()->assertSee(['"drafts":3', '"captions_missing":[', '2 of these posts came without captions, so they are queued as caption jobs']);

    [$with, $without, $empty] = Post::orderBy('id')->get()->all();
    expect($with->variants)->toHaveCount(3)->and($with->settings['ask_claude'] ?? false)->toBeFalse()
        ->and($without->variants)->toHaveCount(0)->and($without->settings['ask_claude'])->toBeTrue()->and($without->settings['ask_claude_at'])->not->toBeNull()
        ->and($empty->settings['ask_claude'])->toBeTrue();
    $response->assertSee(['"post":'.$without->id, '"post":'.$empty->id])->assertDontSee('"post":'.$with->id.',');

    // the queued posts are the worker's caption jobs now
    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()->assertSee(['"post":'.$without->id, '"post":'.$empty->id])->assertDontSee('"post":'.$with->id.',');
});

test('a plan whose posts all have captions answers without captions_missing', function () {
    SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [($this->post)(['captions' => ['el' => ['instagram' => 'Α', 'facebook' => 'Β']]])]])
        ->assertOk()->assertDontSee('captions_missing');
    expect(Post::sole()->settings['ask_claude'] ?? false)->toBeFalse();
});

test('captions keyed platform first or in a language the client does not write are refused, naming the post, and nothing is filed', function () {
    SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [($this->post)(['title' => 'Throws', 'captions' => ['instagram' => ['el' => 'Ζεστά ριχτάρια']]])]])
        ->assertHasErrors(['"Throws": captions go language first, then platform: {el: {instagram: "..."}}, not {instagram: {el: "..."}}.']);
    SocialOpsServer::tool(SavePlan::class, ['client' => 'dokos', 'posts' => [($this->post)(['title' => 'Throws', 'captions' => ['de' => ['instagram' => 'Decken']]])]])
        ->assertHasErrors(['"Throws": "de" is not one of the client\'s languages (el, en) nor hashtags, alt_text, design or notes_for_reviewer.']);
    expect(Post::count())->toBe(0);
});

test('the plan job and the planning brief ask for every post\'s captions', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('clients.queue', $this->client), ['posts' => 3, 'from' => '2027-05-01', 'days' => 14])->assertRedirect();

    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()
        ->assertSee("plan the posts and write every post's captions as the brief's caption_rules say, then save-plan with job");
    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['Write the captions of every post (caption_rules above), the posts are not done without them.', 'language first, then platform.', 'captions_missing']);
});
