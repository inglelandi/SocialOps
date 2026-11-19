<?php

use App\Models\Client;
use App\Models\User;

// Panos, 2026-11-19: "on the date here always use the today date": the Plan with Claude box opened on the first of next
// month (and the API-mode form on tomorrow); both now start from today, which the team moves when it wants another day.

beforeEach(function () {
    $this->travelTo('2026-11-19 10:00:00');
    $this->actingAs(User::factory()->create());
    $this->client = Client::factory()->create(['name' => 'Dokos Home', 'slug' => 'dokos']);
});

test('the Plan with Claude request box starts from today', function () {
    config()->set('features.claude_api', false);
    $html = $this->get(route('clients.planner', $this->client))->assertOk()->getContent();
    expect($html)->toContain("from: '2026-11-19'")->not->toContain('2026-12-01');
});

test('the Plan with Claude form starts from today while Claude runs on the server', function () {
    config()->set('features.claude_api', true);
    $this->get(route('clients.planner', $this->client))->assertOk()
        ->assertSee('name="from" type="date" value="2026-11-19"', false)
        ->assertDontSee('value="2026-11-20"', false);
});
