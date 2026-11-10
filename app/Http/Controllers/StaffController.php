<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff logins. The first one is made on /setup (only while there are no users), the rest on the Staff page, which only admins reach (routes/web.php). */
class StaffController extends Controller
{
    public function index()
    {
        return view('staff.index', ['users' => User::orderBy('name')->get(), 'clients' => Client::orderBy('name')->get(), 'tokens' => ApiToken::with('client')->latest()->get()]);
    }

    public function store(Request $request)
    {
        $this->create($request);

        return redirect()->route('staff.index')->with('status', 'login-added');
    }

    /** A new API token (docs/api.md); the plain token is shown once on the next page. */
    public function tokenStore(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')]]);
        [, $plain] = ApiToken::issue($data['name'], $data['client_id'] ?? null, $request->user()->id);

        return redirect()->route('staff.index')->with('status', 'token-created')->with('token', $plain);
    }

    public function tokenDestroy(ApiToken $token)
    {
        $token->delete();

        return redirect()->route('staff.index')->with('status', 'token-revoked');
    }

    /** Limit a login to some clients, or lift the limit (no client ticked). */
    public function update(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'You cannot limit your own login.');
        $user->forceFill(['client_ids' => $this->clientIds($request), 'is_admin' => $request->boolean('is_admin')])->save();

        return redirect()->route('staff.index')->with('status', 'login-updated');
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'You cannot remove your own login.');
        $user->delete();

        return redirect()->route('staff.index')->with('status', 'login-removed');
    }

    public function setup()
    {
        abort_unless(User::count() === 0, 404);

        return view('staff.setup');
    }

    public function finishSetup(Request $request)
    {
        abort_unless(User::count() === 0, 404);
        auth()->login(tap($this->create($request), fn (User $user) => $user->forceFill(['is_admin' => true])->save())); // the first login owns the Settings page

        return redirect()->route('dashboard');
    }

    private function create(Request $request): User
    {
        $user = User::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:12'],
        ]) + ['client_ids' => $this->clientIds($request)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** The ticked client ids, or null for every client. */
    private function clientIds(Request $request): ?array
    {
        $ids = $request->validate(['client_ids' => ['nullable', 'array'], 'client_ids.*' => ['integer', Rule::exists('clients', 'id')]])['client_ids'] ?? [];

        return $ids ? array_map('intval', array_values($ids)) : null;
    }
}
