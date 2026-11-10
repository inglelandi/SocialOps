<?php

use App\Models\Client;
use App\Models\Post;
use App\Models\User;

// Claude through the MCP connector (config/features.php, D18): the server-side Claude buttons are off.
beforeEach(fn () => config()->set('features.claude_api', false));

test('the Claude buttons and routes do not exist while the API flag is off; the client page shows the MCP request instead', function () {
    $client = Client::factory()->create();
    $post = Post::factory()->create(['client_id' => $client->id]);
    $staff = $this->actingAs(User::factory()->create());

    $staff->post(route('clients.plan', $client), ['posts' => 3])->assertNotFound();
    $staff->post(route('clients.draft', $client), ['instruction' => 'x'])->assertNotFound();
    $staff->post(route('posts.draft', $post))->assertNotFound();
    $staff->post(route('posts.shorten', $post), ['platform' => 'instagram', 'language' => 'el'])->assertNotFound();
    $staff->post(route('posts.rewrite', $post), ['instruction' => 'x'])->assertNotFound();

    $html = $staff->get(route('posts.edit', $post))->assertOk()->getContent();
    expect($html)->not->toContain('Draft captions with Claude')->not->toContain('Change with Claude')->not->toContain('Shorten with Claude');

    $html = $staff->get(route('clients.planner', $client))->assertOk()->getContent();
    expect($html)->toContain('get-planning-brief')->toContain('save-plan')->toContain('client &quot;'.$client->slug.'&quot;')
        ->not->toContain('action="'.route('clients.plan', $client).'"')->not->toContain(route('clients.draft', $client)); // the tab itself is the same address, read with GET
});
