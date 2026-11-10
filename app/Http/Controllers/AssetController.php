<?php

namespace App\Http\Controllers;

use App\Http\Requests\InboxUploadRequest;
use App\Jobs\AssembleReel;
use App\Media\ReelAssembler;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssetController extends Controller
{
    public const LINK_DAYS = 30;

    /** The filters of the files grid, kept in the query string and carried through a delete. */
    public const FILTERS = ['q', 'kind', 'pillar', 'used'];

    /** One queued Reel from the ticked clips; it lands in the inbox like an upload. */
    public function reel(Request $request, Client $client): RedirectResponse
    {
        abort_unless(ReelAssembler::binary(), 404, 'Install ffmpeg on the server first (docs/pilot-setup.md).');
        $data = $request->validateWithBag('reel', [
            'clips' => ['required', 'array', 'min:2', 'max:'.ReelAssembler::MAX_CLIPS],
            'clips.*' => ['integer', Rule::exists('assets', 'id')->where('client_id', $client->id)->where('kind', 'video')],
            'seconds' => ['required', 'integer', 'between:2,15'],
            'headline' => ['nullable', 'string', 'max:80'],
            'pillar' => ['nullable', Rule::in(array_keys($client->profile['pillars']))],
        ]);
        // shortcut: clips play in file-name order, the one phones give them; an order of your own needs drag and drop
        $clips = $client->assets()->whereIn('id', $data['clips'])->orderBy('original_name')->orderBy('id')->pluck('id')->all();
        AssembleReel::dispatch($client, $clips, (int) $data['seconds'], (string) ($data['headline'] ?? ''), $data['pillar'] ?? null);

        return redirect()->route('clients.assets', $client)->with('status', __('Assembling the Reel, it lands in this inbox in a minute or two'))->with('tab', 'reel');
    }

    /**
     * Staff upload from the assets page: the files of one batch share the pillar and tags and are filed like the client's
     * upload link (same types and sizes, agency names), uploaded_via staff. Each file is checked and stored on its own, so a
     * refused or failed file never stops the others. The page sends one file per request to show its progress; a plain form
     * post sends them all.
     */
    public function store(Request $request, Client $client): JsonResponse|RedirectResponse
    {
        $data = $request->validateWithBag('upload', [
            'files' => ['required', 'array', 'min:1', 'max:'.InboxUploadRequest::MAX_FILES],
            'pillar' => ['nullable', Rule::in(array_keys($client->profile['pillars']))],
            'season' => ['nullable', 'string', 'max:30'],
            'people_consent' => ['sometimes', 'boolean'],
            'ugc_permission' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['files.required' => __('Choose at least one photo or video.')]);
        $tags = [
            'pillar' => $data['pillar'] ?? null,
            'season' => $data['season'] ?? null,
            'people_consent' => $request->boolean('people_consent'),
            'ugc_permission' => $request->boolean('ugc_permission'),
            'note' => $data['note'] ?? null,
            'uploaded_via' => 'staff',
        ];

        $uploaded = $failed = [];
        foreach ($request->file('files', []) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $check = Validator::make(['file' => $file], ['file' => InboxUploadRequest::fileRules()], [
                'file.mimetypes' => InboxUploadRequest::typeMessage(),
                'file.max' => InboxUploadRequest::sizeMessage(),
                'file.uploaded' => __('The upload did not finish, try again.'),
            ]);
            if ($check->fails()) {
                $failed[] = ['name' => $file->getClientOriginalName(), 'error' => $check->errors()->first('file')];

                continue;
            }
            try {
                $asset = $client->storeAsset($file, $tags);
                $uploaded[] = ['id' => $asset->id, 'name' => $asset->name, 'original_name' => $asset->original_name];
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['name' => $file->getClientOriginalName(), 'error' => __('Could not be saved, try again.')];
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['uploaded' => $uploaded, 'failed' => $failed], $uploaded ? 201 : 422);
        }
        $back = redirect()->route('clients.assets', $client);
        if ($uploaded) {
            $back->with('status', trans_choice('{1} :n file added|[2,*] :n files added', count($uploaded), ['n' => count($uploaded)]));
        }

        return $failed ? $back->withErrors(array_map(fn (array $f) => $f['name'].': '.$f['error'], $failed), 'upload') : $back;
    }

    /** A small JPEG of an image asset for the grid and lists; 404 for videos. */
    public function thumb(Asset $asset): Response
    {
        return response($asset->preview(600) ?? abort(404), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** The file itself in the browser, for the preview on the assets page (videos play, with byte ranges on the local disk). */
    public function show(Asset $asset): BaseResponse
    {
        return $asset->inline();
    }

    public function download(Asset $asset): StreamedResponse
    {
        return Storage::disk($asset->disk)->download($asset->path, $asset->name);
    }

    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();

        return redirect()->route('clients.assets', ['client' => $asset->client_id] + array_filter($request->only(self::FILTERS)))->with('status', 'asset-deleted');
    }
}
