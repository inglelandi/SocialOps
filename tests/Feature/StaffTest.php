<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

$input = ['name' => 'Panos', 'email' => 'panos@example.com', 'password' => 'twelve-chars-long'];

test('setup creates the first login and then disappears', function () use ($input) {
    $this->get('/')->assertRedirect('/setup');
    $this->get('/setup')->assertOk();

    $this->post('/setup', $input)->assertRedirect('/dashboard');
    $this->assertAuthenticated();
    expect(User::firstOrFail()->email_verified_at)->not->toBeNull()
        ->and(User::firstOrFail()->is_admin)->toBeTrue();

    $this->get('/setup')->assertNotFound();
    $this->post('/setup', ['email' => 'x@example.com'] + $input)->assertNotFound();
});

test('admins add and remove logins but not their own; the Staff page and its link are for admins only', function () use ($input) {
    $me = User::factory()->create(['is_admin' => true]);
    $staff = User::factory()->create(['name' => 'Eleni']);

    $this->get('/staff')->assertRedirect('/login');
    $this->actingAs($staff)->get('/staff')->assertForbidden();
    $this->actingAs($staff)->post('/staff', $input)->assertForbidden();
    $this->actingAs($staff)->post('/staff/tokens', ['name' => 'Sheet'])->assertForbidden();
    $this->actingAs($staff)->get('/dashboard')->assertOk()->assertDontSee(route('staff.index'))->assertDontSee('Refresh after update')->assertDontSee('>Admin<', false);
    $this->actingAs($me)->get('/dashboard')->assertOk()->assertSeeInOrder(['Jobs', 'Admin', 'Staff', 'Settings', 'Refresh after update']);
    $this->actingAs($me)->post('/staff', ['password' => 'short'] + $input)->assertSessionHasErrors('password');
    $this->actingAs($me)->post('/staff', $input)->assertRedirect('/staff');

    $other = User::where('email', $input['email'])->firstOrFail();
    $this->actingAs($me)->get('/staff')->assertOk()->assertSee('Panos')->assertSee('you');
    $this->actingAs($me)->delete("/staff/{$me->id}")->assertStatus(422);
    $this->actingAs($me)->delete("/staff/{$other->id}")->assertRedirect('/staff');
    expect(User::count())->toBe(2);
});

test('the dashboard refresh button migrates and rebuilds the caches', function () {
    $this->post('/refresh')->assertRedirect('/login');

    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true]);
    Artisan::shouldReceive('call')->once()->with('optimize');
    $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/refresh')->assertRedirect('/dashboard')->assertSessionHas('status', 'app-refreshed'); // admins only
    $this->get('/refresh')->assertOk()->assertSee('Refresh after update'); // its own page: the dashboard may be down before the migration runs
});
