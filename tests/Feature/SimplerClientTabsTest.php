<?php

use App\Models\Client;
use App\Models\Post;
use App\Models\User;

// Panos, 2026-11-10: "If we merge some things together and simplify some things it would be great": one Files tab for the
// assets and the files, Plan with Claude a tab of its own, the Instagram grid one of the calendar's views, and the Recycle
// bin a tab only while it holds posts.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create(['name' => 'Dokos Home', 'slug' => 'dokos']);
});

test('the client tabs are Calendar, Plan with Claude, Files, Approvals, Deadlines, Reports and Profile; the Recycle bin shows while it holds posts', function () {
    $html = $this->actingAs($this->user)->get(route('clients.edit', $this->client))->assertOk()
        ->assertSeeInOrder([route('calendar', ['client' => 'dokos']), 'Calendar', route('clients.planner', $this->client), 'Plan with Claude', route('clients.files', $this->client), 'Files',
            route('clients.reviews', $this->client), 'Approvals', route('clients.deadlines', $this->client), 'Deadlines', route('clients.reports', $this->client), 'Reports', route('clients.edit', $this->client), 'Profile', 'New post'], false)
        ->getContent();
    expect($html)->not->toContain('href="'.route('clients.assets', $this->client).'"')->not->toContain('href="'.route('clients.grid', $this->client).'"')
        ->not->toContain('href="'.route('clients.bin', $this->client).'"');

    Post::factory()->count(2)->create(['client_id' => $this->client->id])->each->moveToBin();
    $this->get(route('clients.edit', $this->client))->assertOk()
        ->assertSeeInOrder(['Profile', route('clients.bin', $this->client), 'Recycle bin', '<span class="ml-1 rounded-full bg-brand-100 px-1.5 text-xs text-brand-700">2</span>', 'New post'], false);
    $this->get(route('clients.bin', $this->client))->assertOk()->assertSee('Recycle bin');

    // the Files tab is on for both addresses of the page, Plan with Claude on its own
    foreach (['clients.files', 'clients.assets'] as $route) {
        expect($this->get(route($route, $this->client))->assertOk()->getContent())->toMatch('#<a href="'.preg_quote(route('clients.files', $this->client), '#').'" class="[^"]*bg-white font-medium#');
    }
    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('Plan with Claude')->assertSee('Import posts from a spreadsheet')->assertSee(route('posts.import', $this->client));
    config()->set('features.claude_api', false); // the MCP-mode box: the request for the hourly worker
    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('Queue for Claude')->assertSee('Import posts from a spreadsheet');
});

test('the Instagram grid is one of the calendar views, and the calendar links the Recycle bin', function () {
    Post::factory()->create(['client_id' => $this->client->id])->moveToBin();

    $this->actingAs($this->user)->get(route('calendar', ['client' => 'dokos']))->assertOk()
        ->assertSeeInOrder(['Month', 'Week', 'List', 'href="'.route('clients.grid', $this->client).'" data-view="grid"', 'Grid'], false)
        ->assertSee('href="'.route('clients.bin', $this->client).'" class="ml-auto', false)->assertSee('Recycle bin (1)');
    $this->get(route('calendar'))->assertOk()->assertDontSee('data-view="grid"', false)->assertDontSee('data-bin', false); // no client, no grid or bin

    $html = $this->get(route('clients.grid', $this->client))->assertOk()
        ->assertSee(route('calendar', ['client' => 'dokos', 'view' => 'month']))->assertSee('aria-current="page"', false)->getContent();
    expect($html)->toMatch('#<a href="'.preg_quote(route('calendar', ['client' => 'dokos']), '#').'" class="[^"]*bg-white font-medium#'); // the Calendar tab is on
});
