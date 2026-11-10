<?php

use App\Models\Client;
use App\Models\Post;
use App\Models\User;

test('a login limited to some clients sees only them everywhere, and only an unlimited login manages logins', function () {
    $a = Client::factory()->create(['name' => 'Almyra']);
    $b = Client::factory()->create(['name' => 'Blue Villa']);
    $postA = Post::factory()->create(['client_id' => $a->id, 'scheduled_at' => now()->addDay()]);
    $postB = Post::factory()->create(['client_id' => $b->id, 'scheduled_at' => now()->addDay()]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->post('/staff', ['name' => 'Eleni', 'email' => 'eleni@example.com', 'password' => 'twelve-chars-long', 'client_ids' => [$a->id]])->assertRedirect('/staff');
    $eleni = User::where('email', 'eleni@example.com')->firstOrFail();
    expect($eleni->client_ids)->toBe([$a->id])->and($eleni->canSee($a))->toBeTrue()->and($eleni->canSee($b))->toBeFalse();
    $this->actingAs($admin)->get('/staff')->assertOk()->assertSee('Almyra')->assertSee('all');

    $this->actingAs($eleni)->get('/clients')->assertOk()->assertSee('Almyra')->assertDontSee('Blue Villa');
    $this->actingAs($eleni)->get('/calendar')->assertOk()->assertSee($postA->name)->assertDontSee($postB->name);
    $this->actingAs($eleni)->get('/dashboard')->assertOk();
    $this->actingAs($eleni)->get("/posts/{$postA->id}/edit")->assertOk();
    $this->actingAs($eleni)->get("/posts/{$postB->id}/edit")->assertForbidden();
    $this->actingAs($eleni)->get("/clients/{$b->id}/edit")->assertForbidden();
    $this->actingAs($eleni)->get("/clients/{$b->id}/assets")->assertForbidden();
    $this->actingAs($eleni)->get('/staff')->assertForbidden();
    $this->actingAs($eleni)->post('/staff', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'twelve-chars-long'])->assertForbidden();

    $this->actingAs($admin)->patch("/staff/{$eleni->id}", ['client_ids' => []])->assertRedirect('/staff');
    expect($eleni->fresh()->client_ids)->toBeNull();
    $this->actingAs($admin)->patch("/staff/{$admin->id}", ['client_ids' => [$a->id]])->assertStatus(422);
    $this->actingAs($admin)->patch("/staff/{$eleni->id}", ['client_ids' => [999]])->assertSessionHasErrors('client_ids.0');
    $this->actingAs($eleni->fresh())->get("/posts/{$postB->id}/edit")->assertOk();
});
