<?php

namespace App\Mcp\Tools;

use App\Clients\ClientProfile;
use App\Models\Client;
use App\Planning\PlanRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('The clients this token may see, with their languages, content pillars and platforms (the accounts in the profile). Use the id or slug in the other tools.')]
class ListClients extends Tool
{
    use ScopedToToken;

    public function handle(): Response
    {
        return Response::json($this->clients()->with('accounts')->get()->map(fn (Client $c) => [
            'id' => $c->id,
            'slug' => $c->slug,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
            'location' => $c->location,
            'languages' => array_merge($c->profile['languages'], $c->profile['extra_languages'] ?? []),
            'pillars' => array_keys($c->profile['pillars']),
            // the platforms the client is on: its handles in the profile, plus any account connected for posting (not Canva, Drive or Dropbox)
            'platforms' => array_values(array_intersect(ClientProfile::PLATFORMS, [...PlanRequest::accounts($c->profile), ...$c->accounts->pluck('platform')])),
        ])->values());
    }
}
