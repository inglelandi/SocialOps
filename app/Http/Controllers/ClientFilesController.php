<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientDocuments;
use App\Media\GoogleDrive;
use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The Files tab: the team's posts slideshow of the client (an accounts row with platform drive_slides: one Google Slides
 * file with the posts prepared to go up) and its reference folder on Google Drive (platform drive_docs), with what was read
 * from them, so Claude plans around the prepared posts and from the client's previous posts, reference files and past
 * designs. Separate from the photo inbox folder. The designs' previews come from Drive on each view and are never kept here.
 */
class ClientFilesController extends Controller
{
    /** Designs listed on the tab before "Show all": each one's preview is a Drive call. */
    public const DESIGNS_SHOWN = 60;

    public function index(Request $request, Client $client): View
    {
        $folder = $client->accounts()->where('platform', 'drive_docs')->first();
        $order = array_flip(array_keys(ClientDocument::KINDS));
        [$designs, $documents] = ($folder ? $folder->documents()->get() : collect())->partition(fn (ClientDocument $d) => $d->isDesign());
        $documents = $documents->sortBy([fn (ClientDocument $a, ClientDocument $b) => ($order[$a->kind] <=> $order[$b->kind]) ?: strnatcasecmp($a->path.'/'.$a->name, $b->path.'/'.$b->name)])->values();
        $designs = $designs->sortBy([fn (ClientDocument $a, ClientDocument $b) => ($b->modified_at?->getTimestamp() ?? 0) <=> ($a->modified_at?->getTimestamp() ?? 0) ?: strnatcasecmp($a->name, $b->name)])->values();
        $describable = $designs->filter(fn (ClientDocument $d) => $d->kind === 'design' && $d->error === null);

        $slideshow = $client->accounts()->where('platform', 'drive_slides')->first();

        return view('clients.files', [
            'client' => $client,
            'slideshow' => $slideshow,
            'slideshowDocument' => $slideshow?->documents()->first(),
            'folder' => $folder,
            'documents' => $documents,
            'designs' => $request->boolean('all') ? $designs : $designs->take(self::DESIGNS_SHOWN),
            'designTotal' => $designs->count(),
            'described' => ['done' => $describable->whereNotNull('text')->count(), 'total' => $describable->count()],
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

    /**
     * Link the posts slideshow: checked as the service account, kept as the client's drive_slides row and read right away
     * (one file: the tab shows what was read on the way back). Another file forgets the old one's text.
     */
    public function linkSlideshow(Request $request, Client $client, GoogleDrive $drive): RedirectResponse
    {
        abort_unless(GoogleDrive::email(), 404, 'Add GOOGLE_SERVICE_ACCOUNT_JSON to .env first (docs/pilot-setup.md).');
        $link = $request->validate(['slideshow' => ['required', 'string', 'max:500']])['slideshow'];
        try {
            $file = $drive->file(GoogleDrive::fileId($link));
        } catch (RuntimeException $e) {
            return redirect()->route('clients.files', $client)->withInput()->withErrors(['slideshow' => $e->getMessage()]);
        }
        $account = $client->accounts()->updateOrCreate(['platform' => 'drive_slides'], ['external_id' => $file['id'], 'name' => $file['name'], 'access_token' => '', 'connected_by' => $request->user()?->id, 'meta' => ['file' => $file['id'], 'mime' => $file['mime']]]);
        if (! $account->wasRecentlyCreated && $account->wasChanged('external_id')) {
            $account->documents()->delete();
        }
        SyncClientDocuments::dispatchSync($account);

        return redirect()->route('clients.files', $client)->with('status', __('Linked “:name”, Claude reads it when it plans this client', ['name' => $file['name']]));
    }

    public function syncSlideshow(Client $client): RedirectResponse
    {
        SyncClientDocuments::dispatchSync($client->accounts()->where('platform', 'drive_slides')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Slideshow read again'));
    }

    public function sync(Client $client): RedirectResponse
    {
        SyncClientDocuments::dispatch($client->accounts()->where('platform', 'drive_docs')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Reading the folder now, the list refreshes in a minute or two'));
    }

    /** The team says what a file is for (a document: previous posts, reference or not used; a design: design or not used); a sync never changes it again. */
    public function kind(Request $request, Client $client, ClientDocument $document): RedirectResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys($document->kindChoices()))]])['kind'];
        $document->update(['kind' => $kind, 'kind_set_by_user' => true]);

        return redirect()->route('clients.files', $client);
    }

    /** A design's preview as Drive makes it, passed through for the tab: nothing is written to disk, the cache or the database. */
    public function preview(Client $client, ClientDocument $document, GoogleDrive $drive): Response
    {
        abort_unless($document->isDesign() && $document->error !== ClientDocument::NO_PREVIEW, 404);
        try {
            $preview = $drive->preview($document->source_id, 400);
        } catch (RuntimeException) {
            abort(404);
        }

        return response($preview['bytes'], 200, ['Content-Type' => $preview['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
