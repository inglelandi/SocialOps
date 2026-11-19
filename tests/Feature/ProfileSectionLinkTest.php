<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Js;

// Panos, 2026-11-19: "the link in orange goes to the wrong page if the profil is opened and browsed before". The profile opened
// the section remembered in the browser and ignored the link; it now opens the address's #section first.

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Dokos', 'code' => 'DOKOS', 'slug' => 'dokos']);
    $this->actingAs(User::factory()->create());
});

test('the links to the NAS folder field open the Research section of the profile', function () {
    config()->set('features.claude_api', false); // the MCP-mode box of the Plan with Claude tab
    $research = route('clients.edit', $this->client).'#research';

    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('href="'.$research.'"', false);
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('href="'.$research.'"', false);
});

test('the profile opens the section named in the address before the one opened last in the browser', function () {
    foreach ([route('clients.edit', $this->client), route('clients.create')] as $page) {
        $this->get($page)->assertOk()
            ->assertSee('x-data="clientForm()" x-on:hashchange.window="fromHash()"', false)
            ->assertSee('window.clientForm', false)
            ->assertSee('tabs: '.Js::from(['basics', 'about', 'agency', 'voice', 'content', 'approval', 'timing', 'research', 'more']), false)
            ->assertSee("this.tabs.includes(hash) ? hash : (this.tabs.includes(last) ? last : 'basics')", false)
            ->assertDontSee("localStorage.getItem('client-tab') || 'basics'", false);
    }
});
