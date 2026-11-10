<?php

use App\Models\Client;
use App\Models\User;

test('the profile form is nine sections, with every field still in the page; Plan with Claude and the import have their own tab', function () {
    $client = Client::factory()->create();
    $html = $this->actingAs(User::factory()->create())->get(route('clients.edit', $client))->assertOk()->getContent();

    expect(substr_count($html, 'role="tab"'))->toBe(9);
    foreach (['basics', 'about', 'agency', 'voice', 'content', 'approval', 'timing', 'research', 'more'] as $tab) {
        expect($html)->toContain("x-show=\"tab === '{$tab}'\"");
    }
    expect($html)->not->toContain("x-show=\"tab === 'plan'\"")->not->toContain(route('posts.import', $client))->toContain(route('clients.planner', $client))
        ->toContain('name="name"')->toContain('name="p[about][description]"')->toContain('name="p[voice][words]"')->toContain('name="plan_due"')->toContain('name="p[signatures][instagram]"')->toContain('name="p[slots][0][day]"')
        ->toContain('name="p[research][sources]"')->toContain('name="p[saved_replies][0][label]"')->toContain('name="p[webhooks][0][url]"');
});
