<?php

use App\Models\Client;
use App\Models\User;

test('every empty page says what would be there and offers the next step', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('dashboard'))->assertOk()->assertSee('No clients yet')->assertSee(route('clients.create'));
    $this->get(route('clients.index'))->assertOk()->assertSee('No clients yet')->assertSee(route('clients.create'));

    $client = Client::factory()->create();
    $this->get(route('dashboard'))->assertOk()->assertSee('Nothing needs you right now')->assertSee(route('calendar'));
    $this->get(route('calendar', ['view' => 'list']))->assertOk()->assertSee('Nothing scheduled here yet')->assertSee('data-new-post=""', false);
    $this->get(route('calendar', ['view' => 'list', 'client' => $client->slug]))->assertOk()->assertSee('Nothing scheduled here yet')->assertSee(route('posts.create', $client));
    $this->get(route('clients.reviews', $client))->assertOk()->assertSee('No draft posts waiting')->assertSee(route('posts.create', $client));
    $this->get(route('clients.grid', $client))->assertOk()->assertSee('No Instagram feed posts with a date yet')->assertSee(route('posts.create', $client));
    $this->get(route('clients.assets', $client))->assertOk()->assertSee('Nothing uploaded yet')->assertSee('href="#sources"', false);
    $this->get(route('clients.assets', [$client, 'kind' => 'video']))->assertOk()->assertSee('Nothing matches these filters')->assertSee(route('clients.assets', $client).'"', false);
    $this->get(route('clients.comments', $client))->assertOk()->assertSee('Nothing to answer yet')->assertSee(route('clients.accounts', $client));
    $client->accounts()->create(['platform' => 'instagram', 'external_id' => '1', 'name' => 'almyra', 'access_token' => 't']);
    $this->get(route('clients.comments', $client))->assertOk()->assertSee('Nothing to answer.')->assertDontSee('Connect an account');
    $this->get(route('clients.comments', [$client, 'tab' => 'questions']))->assertOk()->assertSee('the other tabs may still have something')->assertSee(route('clients.comments', $client).'"', false);
    $this->get(route('clients.reports', $client))->assertOk()->assertSee('No reports yet')->assertSee(route('clients.edit', $client));
});
