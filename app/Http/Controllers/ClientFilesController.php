<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientDocuments;
use App\Media\GoogleDrive;
use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The Files tab: a client's reference folder on Google Drive (an accounts row with platform drive_docs) and what was read
 * from it, so Claude plans from the client's previous posts and reference files. Separate from the photo inbox folder.
 */
class ClientFilesController extends Controller
{
    public function index(Client $client): View
    {
        $folder = $client->accounts()->where('platform', 'drive_docs')->first();
        $order = array_flip(array_keys(ClientDocument::KINDS));
        $documents = $folder ? $folder->documents()->get()->sortBy([fn (ClientDocument $a, ClientDocument $b) => ($order[$a->kind] <=> $order[$b->kind]) ?: strnatcasecmp($a->path.'/'.$a->name, $b->path.'/'.$b->name)])->values() : collect();

        return view('clients.files', [
            'client' => $client,
            'folder' => $folder,
            'documents' => $documents,
            'driveEmail' => GoogleDrive::email(),
            // the photo inbox folder of the assets page: offered with one click, so the same link is not pasted twice
            'inbox' => $folder ? null : $client->accounts()->where('platform', 'drive')->first(),
        ]);
    }

    /** Link a folder: checked as the service account, kept as the client's drive_docs row, read right away. A new folder forgets the old one's files. */
    public function link(Request $request, Client $client, GoogleDrive $drive): RedirectResponse
    {
        abort_unless(GoogleDrive::email(), 404, 'Add GOOGLE_SERVICE_ACCOUNT_JSON to .env first (docs/pilot-setup.md).');
        $link = $request->validate(['folder' => ['required', 'string', 'max:500']])['folder'];
        try {
            $folder = $drive->folder(GoogleDrive::folderId($link));
        } catch (RuntimeException $e) {
            return redirect()->route('clients.files', $client)->withInput()->withErrors(['folder' => $e->getMessage()]);
        }
        $account = $client->accounts()->updateOrCreate(['platform' => 'drive_docs'], ['external_id' => $folder['id'], 'name' => $folder['name'], 'access_token' => '', 'connected_by' => $request->user()?->id, 'meta' => ['folder' => $folder['id'], 'folder_name' => $folder['name']]]);
        if (! $account->wasRecentlyCreated && $account->wasChanged('external_id')) {
            $account->documents()->delete();
        }
        SyncClientDocuments::dispatch($account);

        return redirect()->route('clients.files', $client)->with('status', __('Linked “:name”, its files are being read', ['name' => $folder['name']]));
    }

    public function sync(Client $client): RedirectResponse
    {
        SyncClientDocuments::dispatch($client->accounts()->where('platform', 'drive_docs')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Reading the folder now, the list refreshes in a minute or two'));
    }

    /** The team says what a file is for; a sync never changes it again. */
    public function kind(Request $request, Client $client, ClientDocument $document): RedirectResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys(ClientDocument::KINDS))]])['kind'];
        $document->update(['kind' => $kind, 'kind_set_by_user' => true]);

        return redirect()->route('clients.files', $client);
    }
}
