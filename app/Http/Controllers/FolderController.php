<?php

namespace App\Http\Controllers;

use App\Jobs\PullFolder;
use App\Media\Dropbox;
use App\Media\GoogleDrive;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/** Watching a shared Google Drive folder or a Dropbox folder for a client (the two boxes on the assets page). */
class FolderController extends Controller
{
    public function watchDrive(Request $request, Client $client, GoogleDrive $drive): RedirectResponse
    {
        abort_unless(GoogleDrive::email(), 404, 'Add GOOGLE_SERVICE_ACCOUNT_JSON to .env first (docs/pilot-setup.md).');
        $link = $request->validate(['folder' => ['required', 'string', 'max:500']])['folder'];
        try {
            $folder = $drive->folder(GoogleDrive::folderId($link));
        } catch (RuntimeException $e) {
            return redirect()->route('clients.assets', $client)->withInput()->withErrors(['drive' => $e->getMessage()]);
        }
        $account = $client->accounts()->updateOrCreate(['platform' => 'drive'], ['external_id' => $folder['id'], 'name' => $folder['name'], 'access_token' => '', 'connected_by' => $request->user()?->id, 'meta' => ['folder' => $folder['id'], 'folder_name' => $folder['name']]]);

        return $this->watching($account, $folder['name']);
    }

    public function connectDropbox(Request $request, Client $client, Dropbox $dropbox): RedirectResponse
    {
        abort_unless(config('services.dropbox.app_key'), 404, 'Add DROPBOX_APP_KEY to .env first (docs/pilot-setup.md).');
        $state = Str::random(40);
        $verifier = Dropbox::verifier();
        $request->session()->put('dropbox', ['state' => $state, 'verifier' => $verifier, 'client' => $client->id]);

        return redirect()->away($dropbox->authorizeUrl($state, $verifier));
    }

    public function dropboxCallback(Request $request, Dropbox $dropbox): RedirectResponse
    {
        $pending = $request->session()->get('dropbox');
        abort_unless($pending && hash_equals($pending['state'], (string) $request->query('state')), 403, 'OAuth state mismatch');
        $request->session()->forget('dropbox');
        $client = Client::findOrFail($pending['client']);
        $back = redirect()->route('clients.assets', $client);
        if (! $request->query('code')) {
            return $back->withErrors(['dropbox' => $request->query('error_description', $request->query('error', 'Dropbox returned no code.'))]);
        }
        try {
            $client->accounts()->updateOrCreate(['platform' => 'dropbox'], $dropbox->exchange($request->query('code'), $pending['verifier']) + ['connected_by' => $request->user()?->id, 'token_alerted_at' => null, 'meta' => null]);
        } catch (RuntimeException $e) {
            return $back->withErrors(['dropbox' => $e->getMessage()]);
        }

        return $back->with('status', 'dropbox-connected');
    }

    public function watchDropbox(Request $request, Client $client, Dropbox $dropbox): RedirectResponse
    {
        $account = $client->accounts()->where('platform', 'dropbox')->firstOrFail();
        $path = $request->validate(['folder' => ['required', 'string', 'max:500']])['folder'];
        try {
            $folder = $dropbox->folder($account, $path);
        } catch (RuntimeException $e) {
            return redirect()->route('clients.assets', $client)->withInput()->withErrors(['dropbox' => $e->getMessage()]);
        }
        $account->forceFill(['meta' => $folder])->save();

        return $this->watching($account, $folder['folder_name']);
    }

    public function pull(Client $client, string $platform): RedirectResponse
    {
        abort_unless(isset(PullFolder::SOURCES[$platform]), 404);
        PullFolder::dispatch($client->accounts()->where('platform', $platform)->whereNotNull('meta')->firstOrFail());

        return redirect()->route('clients.assets', $client)->with('status', __('Checking the folder now, new files land in a minute or two'))->with('tab', 'sources');
    }

    private function watching($account, string $name): RedirectResponse
    {
        PullFolder::dispatch($account);

        return redirect()->route('clients.assets', $account->client_id)->with('status', __('Now watching “:name”, its files are on their way', ['name' => $name]))->with('tab', 'sources');
    }
}
