<?php

namespace App\Http\Controllers;

use App\Jobs\ImportCanvaDesign;
use App\Media\Canva;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Connecting the agency's Canva to a client and importing designs into its inbox (the Canva box on the assets page). */
class CanvaController extends Controller
{
    public function connect(Request $request, Client $client, Canva $canva): RedirectResponse
    {
        abort_unless(config('services.canva.client_id'), 404, 'Add CANVA_CLIENT_ID and CANVA_CLIENT_SECRET to .env first (docs/pilot-setup.md).');
        $state = Str::random(40);
        $verifier = Canva::verifier();
        $request->session()->put('canva', ['state' => $state, 'verifier' => $verifier, 'client' => $client->id]);

        return redirect()->away($canva->authorizeUrl($state, $verifier));
    }

    public function callback(Request $request, Canva $canva): RedirectResponse
    {
        $pending = $request->session()->get('canva');
        abort_unless($pending && hash_equals($pending['state'], (string) $request->query('state')), 403, 'OAuth state mismatch');
        $request->session()->forget('canva');
        $client = Client::findOrFail($pending['client']);
        $back = redirect()->route('clients.assets', $client);
        if (! $request->query('code')) {
            return $back->withErrors(['canva' => $request->query('error_description', $request->query('error', 'Canva returned no code.'))]);
        }
        try {
            $client->accounts()->updateOrCreate(['platform' => 'canva'], $canva->exchange($request->query('code'), $pending['verifier']) + ['connected_by' => $request->user()?->id, 'token_alerted_at' => null]);
        } catch (RuntimeException $e) {
            return $back->withErrors(['canva' => $e->getMessage()]);
        }

        return $back->with('status', 'canva-connected');
    }

    /** One queued import per ticked design; the files land in the inbox within a minute or two. */
    public function import(Request $request, Client $client): RedirectResponse
    {
        $account = $client->accounts()->where('platform', 'canva')->firstOrFail();
        $data = $request->validate([
            'designs' => ['required', 'array', 'min:1', 'max:20'],
            'designs.*' => ['string', 'max:100'],
            'titles' => ['sometimes', 'array'],
            'titles.*' => ['nullable', 'string', 'max:200'],
            'vertical' => ['sometimes', 'array'],
            'vertical.*' => ['boolean'],
            'type' => ['required', Rule::in(['png', 'jpg', 'mp4'])],
            'pillar' => ['nullable', Rule::in(array_keys($client->profile['pillars']))],
        ]);
        foreach ($data['designs'] as $id) {
            ImportCanvaDesign::dispatch($account, $id, (string) ($data['titles'][$id] ?? ''), $data['type'], (bool) ($data['vertical'][$id] ?? false), $data['pillar'] ?? null);
        }

        return redirect()->route('clients.assets', $client)->with('status', __(':n Canva imports queued', ['n' => count($data['designs'])]))->with('tab', 'sources');
    }
}
