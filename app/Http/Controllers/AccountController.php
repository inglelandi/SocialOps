<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Posts\PostImporter;
use App\Publishing\Publishers;
use App\Publishing\PublishFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Connecting a client's platform accounts: official OAuth only, the staff member picks which page or location is the client's. */
class AccountController extends Controller
{
    public const LINK_DAYS = 7;

    public function index(Client $client): View
    {
        return view('accounts.index', ['client' => $client, 'accounts' => $client->accounts->keyBy('platform'), 'providers' => Publishers::PROVIDERS, 'connectUrl' => URL::temporarySignedRoute('connect.show', now()->addDays(self::LINK_DAYS), ['client' => $client->slug])]);
    }

    /** The page behind the client's signed link: they connect their own accounts with their own login. */
    public function clientConnect(Client $client): View
    {
        return view('accounts.connect', [
            'client' => $client,
            'accounts' => $client->accounts->keyBy('platform'),
            'providers' => Publishers::PROVIDERS,
            'links' => collect(Publishers::PROVIDERS)->map(fn ($platforms, $provider) => URL::temporarySignedRoute('connect.start', now()->addHour(), ['client' => $client->slug, 'provider' => $provider])),
        ]);
    }

    public function connect(Request $request, Client $client, string $provider): RedirectResponse
    {
        abort_unless(isset(Publishers::PROVIDERS[$provider]), 404);
        $state = Str::random(40);
        $return = $request->user() ? route('clients.accounts', $client) : URL::temporarySignedRoute('connect.show', now()->addDay(), ['client' => $client->slug]);
        $request->session()->put('oauth', ['state' => $state, 'client' => $client->id, 'provider' => $provider, 'return' => $return]);

        return redirect()->away(Publishers::provider($provider)->authorizeUrl($state));
    }

    public function callback(Request $request, string $provider): View|RedirectResponse
    {
        $pending = $request->session()->get('oauth');
        abort_unless($pending && $pending['provider'] === $provider && hash_equals($pending['state'], (string) $request->query('state')), 403, 'OAuth state mismatch');
        $client = Client::findOrFail($pending['client']);
        if (! $request->query('code')) {
            return redirect($pending['return'])->withErrors(['connect' => $request->query('error_description', $request->query('error', 'The platform returned no code.'))]);
        }

        try {
            $candidates = Publishers::provider($provider)->exchange($request->query('code'));
        } catch (PublishFailed $e) {
            return redirect($pending['return'])->withErrors(['connect' => $e->getMessage()]);
        }
        $request->session()->put('oauth.candidates', $candidates);

        return view('accounts.pick', ['client' => $client, 'candidates' => $candidates, 'provider' => $provider, 'return' => $pending['return']]);
    }

    /** Save the picked candidates; one account per platform per client. */
    public function attach(Request $request, Client $client): RedirectResponse
    {
        $pending = $request->session()->pull('oauth');
        abort_unless($pending && $pending['client'] === $client->id && isset($pending['candidates']), 403);
        $picked = $request->validate(['picked' => ['required', 'array', 'min:1'], 'picked.*' => ['integer', 'between:0,'.(count($pending['candidates']) - 1)]])['picked'];

        foreach (array_unique($picked) as $i) {
            $candidate = $pending['candidates'][$i];
            $client->accounts()->updateOrCreate(['platform' => $candidate['platform']], $candidate + ['connected_by' => $request->user()?->id, 'token_alerted_at' => null]);
        }

        return redirect($pending['return'])->with('status', 'account-connected');
    }

    /** Pull in what the client published themselves in the last 30 days (Instagram and Facebook only). */
    public function import(Account $account, PostImporter $importer): RedirectResponse
    {
        abort_unless(in_array($account->platform, ['instagram', 'facebook'], true), 404);
        try {
            $n = $importer->import($account);
        } catch (PublishFailed $e) {
            return redirect()->route('clients.accounts', $account->client_id)->withErrors(['connect' => $e->getMessage()]);
        }

        return redirect()->route('clients.accounts', $account->client_id)->with('status', __(':n posts imported', ['n' => $n]));
    }

    /** Disconnect an account; also unlinks an intake folder (Drive, Dropbox, Canva: back to the assets page), the reference folder or the posts slideshow (back to the Files tab, what was read goes with it). */
    public function destroy(Account $account): RedirectResponse
    {
        $account->delete();

        return match ($account->platform) {
            'drive_docs' => redirect()->route('clients.files', $account->client_id)->with('status', __('Folder unlinked, its files are forgotten here')),
            'drive_slides' => redirect()->route('clients.files', $account->client_id)->with('status', __('Slideshow unlinked, its text is forgotten here')),
            'drive', 'dropbox', 'canva' => redirect()->route('clients.assets', $account->client_id)->with('status', 'account-disconnected'),
            default => redirect()->route('clients.accounts', $account->client_id)->with('status', 'account-disconnected'),
        };
    }
}
