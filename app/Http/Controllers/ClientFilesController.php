<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientDocuments;
use App\Jobs\SyncProductFeed;
use App\Media\Canva;
use App\Media\GoogleDrive;
use App\Media\ReelAssembler;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientProduct;
use App\Products\ProductFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The client's Files page, one side list (Panos, 2026-11-10: "the assets and files should be one … as side menu like the
 * profile"): the photos and videos (the asset inbox), the other ways in (the client's upload link, Drive, Dropbox, Canva)
 * and the Reel maker, then what Claude reads when it plans: the team's posts slideshow (an accounts row with platform
 * drive_slides), the reference folder on Google Drive (drive_docs, separate from the photo inbox folder) with its documents
 * and designs, and the shop's product feed. The designs' previews come from Drive on each view and are never kept here.
 * Actions redirect with `tab` in the session, so the page opens on the section they belong to.
 */
class ClientFilesController extends Controller
{
    /** Designs listed on the tab before "Show all": each one's preview is a Drive call. */
    public const DESIGNS_SHOWN = 60;

    /** Products listed on the tab while nothing is searched, by name. */
    public const PRODUCTS_SHOWN = 30;

    /** The page; /clients/{id}/assets is the same page, opened on the photos and videos. */
    public function index(Request $request, Client $client, Canva $canva): View
    {
        $folder = $client->accounts()->where('platform', 'drive_docs')->first();
        $order = array_flip(array_keys(ClientDocument::KINDS));
        [$designs, $documents] = ($folder ? $folder->documents()->get() : collect())->partition(fn (ClientDocument $d) => $d->isDesign());
        $documents = $documents->sortBy([fn (ClientDocument $a, ClientDocument $b) => ($order[$a->kind] <=> $order[$b->kind]) ?: strnatcasecmp($a->path.'/'.$a->name, $b->path.'/'.$b->name)])->values();
        $designs = $designs->sortBy([fn (ClientDocument $a, ClientDocument $b) => ($b->modified_at?->getTimestamp() ?? 0) <=> ($a->modified_at?->getTimestamp() ?? 0) ?: strnatcasecmp($a->name, $b->name)])->values();
        $describable = $designs->filter(fn (ClientDocument $d) => $d->kind === 'design' && $d->error === null);

        $slideshow = $client->accounts()->where('platform', 'drive_slides')->first();
        $feed = $client->accounts()->where('platform', 'product_feed')->first();
        $productQuery = trim((string) $request->query('product', '')); // not q: that is the photo search of the same page

        return view('clients.files', $this->media($request, $client, $canva) + [
            'client' => $client,
            'section' => $request->routeIs('clients.assets') ? 'media' : null,
            'slideshow' => $slideshow,
            'slideshowDocument' => $slideshow?->documents()->first(),
            'feed' => $feed,
            'productQuery' => $productQuery,
            'productTotal' => $feed ? $client->products()->count() : 0,
            // the search Claude's find-products makes, out of stock included, so the team sees what Claude would find
            'products' => ! $feed ? collect() : ($productQuery !== '' ? ClientProduct::search($client, $productQuery, ClientProduct::SEARCH_MAX, false) : $client->products()->orderBy('name')->limit(self::PRODUCTS_SHOWN)->get()),
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

    /** The photos and videos with the grid's filters (AssetController::FILTERS), the other ways in and the Reel maker's clips. */
    private function media(Request $request, Client $client, Canva $canva): array
    {
        $canvaAccount = $client->accounts()->where('platform', 'canva')->first();
        $canvaDesigns = $canvaError = null;
        if ($canvaAccount && $request->has('canva')) {
            try {
                $canvaDesigns = $canva->designs($canvaAccount, $request->input('canva') ?: null);
            } catch (RuntimeException $e) {
                [$canvaDesigns, $canvaError] = [[], $e->getMessage()];
            }
        }
        $usedBy = fn ($q) => $q->withTrashed(); // a post in the recycle bin still uses its media
        $term = trim((string) $request->input('q'));

        return [
            'assets' => $client->assets()->with(['posts' => $usedBy])
                ->when($request->input('kind'), fn ($q, $kind) => $q->where('kind', $kind))
                ->when($request->input('pillar'), fn ($q, $pillar) => $q->where('pillar', $pillar))
                ->when($request->input('used') === 'yes', fn ($q) => $q->whereHas('posts', $usedBy))
                ->when($request->input('used') === 'no', fn ($q) => $q->whereDoesntHave('posts', $usedBy))
                // the name, the file's own name and the tags; a pillar typed as shown ("food drink") finds food_drink
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('original_name', 'like', "%$term%")
                    ->orWhere('season', 'like', "%$term%")->orWhere('note', 'like', "%$term%")->orWhere('pillar', 'like', '%'.str_replace(' ', '_', $term).'%')))
                ->latest()->latest('id')->get(),
            'assetTotal' => $client->assets()->count(),
            'filtered' => (bool) array_filter($request->only(AssetController::FILTERS)),
            'videos' => $client->assets()->where('kind', 'video')->orderBy('original_name')->orderBy('id')->get(), // the Reel tool's clips, in the order they play
            'inboxUrl' => URL::temporarySignedRoute('inbox.show', now()->addDays(AssetController::LINK_DAYS), ['client' => $client->slug]),
            'canva' => $canvaAccount,
            'drive' => $client->accounts()->where('platform', 'drive')->first(),
            'dropbox' => $client->accounts()->where('platform', 'dropbox')->first(),
            'dropboxReady' => (bool) config('services.dropbox.app_key'),
            'canvaReady' => (bool) config('services.canva.client_id'),
            'reelReady' => ReelAssembler::binary() !== null,
            'canvaDesigns' => $canvaDesigns,
            'canvaError' => $canvaError,
        ];
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

        return redirect()->route('clients.files', $client)->with('status', __('Linked “:name”, its files are being read', ['name' => $folder['name']]))->with('tab', 'folder');
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

        return redirect()->route('clients.files', $client)->with('status', __('Linked “:name”, Claude reads it when it plans this client', ['name' => $file['name']]))->with('tab', 'slideshow');
    }

    public function syncSlideshow(Client $client): RedirectResponse
    {
        SyncClientDocuments::dispatchSync($client->accounts()->where('platform', 'drive_slides')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Slideshow read again'))->with('tab', 'slideshow');
    }

    /**
     * Link the shop's product feed: the address checked (a public http(s) host), kept as the client's product_feed row with
     * the link in meta.url, read in the queue. Another link in its place forgets the old products.
     */
    public function linkFeed(Request $request, Client $client): RedirectResponse
    {
        $url = trim($request->validate(['feed' => ['required', 'string', 'max:2000']])['feed']);
        try {
            ProductFeed::guard($url);
        } catch (RuntimeException $e) {
            return redirect()->route('clients.files', $client)->withInput()->withErrors(['feed' => $e->getMessage()]);
        }
        $account = $client->accounts()->updateOrCreate(['platform' => 'product_feed'], ['external_id' => sha1($url), 'name' => (string) parse_url($url, PHP_URL_HOST), 'access_token' => '', 'connected_by' => $request->user()?->id, 'meta' => ['url' => $url]]);
        if (! $account->wasRecentlyCreated && $account->wasChanged('external_id')) {
            $account->products()->delete();
        }
        SyncProductFeed::dispatch($account);

        return redirect()->route('clients.files', $client)->with('status', __('Linked the product feed of :host, its products are being read', ['host' => $account->name]))->with('tab', 'feed');
    }

    public function syncFeed(Client $client): RedirectResponse
    {
        SyncProductFeed::dispatch($client->accounts()->where('platform', 'product_feed')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Reading the product feed now, the list refreshes in a minute or two'))->with('tab', 'feed');
    }

    public function sync(Client $client): RedirectResponse
    {
        SyncClientDocuments::dispatch($client->accounts()->where('platform', 'drive_docs')->firstOrFail());

        return redirect()->route('clients.files', $client)->with('status', __('Reading the folder now, the list refreshes in a minute or two'))->with('tab', 'folder');
    }

    /** The team says what a file is for (a document: previous posts, reference or not used; a design: design or not used); a sync never changes it again. */
    public function kind(Request $request, Client $client, ClientDocument $document): RedirectResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(array_keys($document->kindChoices()))]])['kind'];
        $document->update(['kind' => $kind, 'kind_set_by_user' => true]);

        return redirect()->route('clients.files', $client)->with('tab', $document->isDesign() ? 'designs' : 'folder');
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
