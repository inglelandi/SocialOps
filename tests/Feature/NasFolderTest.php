<?php

use App\Clients\ClientProfile;
use App\Mcp\SocialOpsServer;
use App\Mcp\Tools\GetCaptionBrief;
use App\Mcp\Tools\GetPlanningBrief;
use App\Mcp\Tools\ListClaudeJobs;
use App\Mcp\Tools\ListClients;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Planning\NasFolder;

// D29: the client's folder on the office NAS is named in the profile; Claude opens it itself through the Inglelandi NAS
// connector, Social Ops only tells it which folder and how.

beforeEach(function () {
    $this->client = Client::factory()->create(['name' => 'Dokos', 'code' => 'DOKOS', 'slug' => 'dokos']);
});

function nasProfileForm(Client $client, string $folder): array
{
    return ['name' => $client->name, 'slug' => $client->slug, 'code' => $client->code, 'type' => $client->type, 'location' => $client->location, 'p' => [
        'languages' => 'el, en',
        'voice' => ['tone' => 'warm', 'person' => 'we', 'emoji' => 'few', 'cta_style' => 'soft'],
        'pillars' => [['name' => 'place', 'share' => '100']],
        'approval' => ['policy' => 'required', 'sla_hours' => '48'],
        'nas_folder' => $folder,
    ]];
}

test('a path copied from Explorer or the NAS keeps only the part inside Clients, and nothing outside Clients is accepted', function () {
    expect(ClientProfile::nasFolder('\\\\inglelandi\\Inglelandi\\Clients\\Dokos Home'))->toBe('Dokos Home')
        ->and(ClientProfile::nasFolder('Z:\\Clients\\Dokos Home\\Instagram Posts\\'))->toBe('Dokos Home/Instagram Posts')
        ->and(ClientProfile::nasFolder('/volume1/Inglelandi/Clients/Dokos Home'))->toBe('Dokos Home')
        ->and(ClientProfile::nasFolder(' Mavres Winery/ '))->toBe('Mavres Winery')
        ->and(ClientProfile::nasFolder('\\\\inglelandi\\Inglelandi\\Clients'))->toBe('');

    foreach (['Dokos Home', 'Dokos Home/Instagram Posts', 'Ναυτικός Όμιλος Χανίων', ''] as $ok) {
        expect(ClientProfile::nasFolderOk($ok))->toBeTrue();
    }
    foreach (['../secret', 'Dokos Home/../Invoices', 'Dokos Home/.hidden', ClientProfile::nasFolder('/volume1/other'), 'C:/Users'] as $bad) {
        expect(ClientProfile::nasFolderOk($bad))->toBeFalse();
    }
});

test('the team names the folder in the profile form, and a folder outside Clients is refused', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('clients.edit', $this->client))->assertOk()->assertSee('Folder on the office NAS')->assertSee('name="p[nas_folder]"', false);

    $this->patch(route('clients.update', $this->client), nasProfileForm($this->client, '\\\\inglelandi\\Inglelandi\\Clients\\Dokos Home\\'))->assertSessionHasNoErrors();
    expect($this->client->fresh()->profile['nas_folder'])->toBe('Dokos Home');
    $this->get(route('clients.edit', $this->client))->assertSee('value="Dokos Home"', false);

    $this->patch(route('clients.update', $this->client), nasProfileForm($this->client, '../Invoices'))
        ->assertSessionHasErrors(['profile' => 'The NAS folder is a folder inside Clients, such as Dokos Home or Dokos Home/Instagram Posts.']);
    expect($this->client->fresh()->profile['nas_folder'])->toBe('Dokos Home');

    $this->patch(route('clients.update', $this->client), nasProfileForm($this->client, ''))->assertSessionHasNoErrors();
    expect(NasFolder::path($this->client->fresh()))->toBeNull();
});

test('the planning and caption briefs and list-clients name the folder and say how to open it; without one they say nothing', function () {
    $post = Post::factory()->create(['client_id' => $this->client->id, 'platforms' => ['instagram']]);

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()->assertSee('"nas_folder":null');
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $post->id])->assertOk()->assertSee('"nas_folder":null');
    SocialOpsServer::tool(ListClients::class)->assertOk()->assertSee('"nas_folder":null');

    $this->client->update(['profile' => ['nas_folder' => 'Dokos Home'] + $this->client->profile]);

    SocialOpsServer::tool(GetPlanningBrief::class, ['client' => 'dokos', 'posts' => 3])->assertOk()
        ->assertSee(['"path":"Dokos Home"', '"connector":"Inglelandi NAS"', 'Before you plan', 'list_folder, search_files, read_document, view_image', 'never planned again', 'Open only this folder for this client', 'notes_for_reviewer']);
    SocialOpsServer::tool(GetCaptionBrief::class, ['post' => $post->id])->assertOk()
        ->assertSee(['"path":"Dokos Home"', 'Before you write', 'this post\'s design', 'Open only this folder for this client']);
    SocialOpsServer::tool(ListClients::class)->assertOk()->assertSee('"nas_folder":"Dokos Home"');
});

test('a queued plan says to open the NAS folder, and the Plan with Claude box and the Files page show it', function () {
    $this->client->reports()->create(['kind' => 'claude_job', 'title' => 'Plan 4 posts', 'body' => '', 'meta' => ['posts' => 4, 'from' => '2027-05-01', 'days' => 30]]);
    SocialOpsServer::tool(ListClaudeJobs::class)->assertOk()->assertSee('open the client\'s NAS folder when the brief names one');

    config()->set('features.claude_api', false); // the MCP-mode box
    $this->actingAs(User::factory()->create());
    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('Name the client\'s folder on the office NAS in the profile (Research), so Claude opens it too.');
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('Office NAS folder')->assertSee('No folder named yet.');

    $this->client->update(['profile' => ['nas_folder' => 'Dokos Home/Instagram Posts'] + $this->client->profile]);
    $this->get(route('clients.planner', $this->client))->assertOk()->assertSee('Claude also opens the client\'s folder “Dokos Home/Instagram Posts” on the office NAS, through the Inglelandi NAS connector.');
    $this->get(route('clients.files', $this->client))->assertOk()->assertSee('Clients/Dokos Home/Instagram Posts')->assertSee('has to be ticked on its scheduled task');
});
