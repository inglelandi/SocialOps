<?php

namespace App\Http\Controllers;

use App\Http\Requests\InboxUploadRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** The client's no-login upload page, reachable only through a signed link. */
class InboxController extends Controller
{
    public function show(Client $client): View
    {
        return view('inbox.show', ['client' => $client]);
    }

    /**
     * An upload bigger than PHP's post_max_size is refused before the session and the route are up (PostTooLargeException,
     * bootstrap/app.php): a page in the client's language with the way back, instead of the bare English 413.
     */
    public static function tooLarge(Request $request): Response
    {
        App::setLocale(Client::firstWhere('slug', (string) $request->segment(2))?->uiLocale() ?? config('app.locale'));

        return response()->view('inbox.too-large', ['back' => $request->getRequestUri()], 413);
    }

    public function store(InboxUploadRequest $request, Client $client): RedirectResponse
    {
        $names = [];
        foreach ($request->file('files') as $file) {
            $names[] = $client->storeAsset($file, [
                'pillar' => $request->pillar,
                'season' => $request->season,
                'people_consent' => $request->boolean('people_consent'),
                'ugc_permission' => $request->boolean('ugc_permission'),
                'note' => $request->note,
            ])->name;
        }

        // the GET and POST share one signed URL, so the redirect keeps the signature
        return redirect($request->fullUrl())->with('uploaded', $names);
    }
}
