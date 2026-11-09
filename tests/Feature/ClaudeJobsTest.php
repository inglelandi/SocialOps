<?php

use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\SavePlan;
use App\Mcp\Tools\UpdatePost;
use App\Models\Client;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

test('the team queues captions and plans for Claude in the app; the scheduled worker lists the jobs and clears them as it files the work', function () {
    $client = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA', 'slug' => 'almyra']);
    $pillar = array_key_first($client->profile['pillars']);
    $user = User::factory()->create();
    $post = Post::factory()->create(['client_id' => $client->id, 'platforms' => ['instagram'], 'format' => 'feed']);

    $this->actingAs($user)->patch(route('posts.update', $post), ['platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'brief' => 'Full moon party', 'settings' => ['ask_claude' => '1']])->assertSessionHasNoErrors();
    expect($post->fresh()->settings['ask_claude'])->toBeTrue();
    $this->actingAs($user)->post(route('clients.queue', $client), ['posts' => 6, 'from' => '2027-05-01', 'days' => 31, 'instruction' => 'Full moon parties'])->assertSessionHasNoErrors()->assertRedirect(route('clients.edit', $client));
    $job = Report::where('kind', 'claude_job')->firstOrFail();
    expect($job->meta)->toMatchArray(['posts' => 6, 'from' => '2027-05-01', 'days' => 31, 'instruction' => 'Full moon parties'])->and($job->title)->toBe('Πλάνο 6 αναρτήσεων από 2027-05-01');

    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()
        ->assertSee(['"post":'.$post->id, route('posts.edit', $post), 'get-caption-brief', '"job":'.$job->id, 'Full moon parties', "save-plan with job {$job->id}"]);

    SocialOpsServer::tool(UpdatePost::class, ['post' => $post->id, 'settings' => ['ask_claude' => false]])->assertOk();
    SocialOpsServer::tool(SavePlan::class, ['client' => 'almyra', 'job' => $job->id, 'posts' => [['date' => '2027-05-12', 'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'title' => 'Full moon', 'angle' => 'Party on the beach.', 'captions' => ['el' => ['instagram' => 'Πανσέληνος στην παραλία']]]]])->assertOk(); // with its captions, so no caption job is left behind
    SocialOpsServer::tool(ListClaudeJobs::class, [])->assertOk()->assertSee(['"captions":[]', '"plans":[]']);
    expect(Report::where('kind', 'claude_job')->count())->toBe(0);
});
