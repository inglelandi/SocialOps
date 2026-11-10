<?php

use App\Jobs\PublishPost;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use App\Posts\PostState;
use App\Publishing\PublishFailed;
use App\Webhooks\Webhooks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_admin' => true]); // the Staff page, where tokens are made, is for admins
    $this->a = Client::factory()->create(['name' => 'Almyra', 'code' => 'ALMYRA']);
    $this->b = Client::factory()->create(['name' => 'Blue Villa']);
});

test('staff issue and revoke API tokens; a token reads clients, creates a Draft and reads it back, and stays within its client', function () {
    $this->actingAs($this->staff)->post('/staff/tokens', ['name' => 'Reporting sheet'])->assertRedirect('/staff')->assertSessionHas('token');
    $plain = session('token');
    expect($plain)->toStartWith('so_')->and(ApiToken::first())->toMatchArray(['name' => 'Reporting sheet', 'client_id' => null, 'token' => hash('sha256', $plain), 'created_by' => $this->staff->id]);
    $this->actingAs($this->staff)->get('/staff')->assertOk()->assertSee('Reporting sheet')->assertSee($plain);

    $this->getJson('/api/clients')->assertUnauthorized();
    $this->withToken('so_wrong')->getJson('/api/clients')->assertUnauthorized();
    $this->withToken($plain)->getJson('/api/clients')->assertOk()->assertJsonCount(2)->assertJsonPath('0.name', 'Almyra')->assertJsonPath('1.slug', $this->b->slug);
    expect(ApiToken::first()->last_used_at)->not->toBeNull();

    $pillar = array_key_first($this->a->profile['pillars']);
    $created = $this->withToken($plain)->postJson("/api/clients/{$this->a->id}/posts", [
        'platforms' => ['instagram'], 'format' => 'feed', 'pillar' => $pillar, 'brief' => 'Sea bass', 'scheduled_at' => '2027-05-12 18:00',
        'variants' => ['instagram' => ['el' => ['caption' => 'Λαβράκι', 'hashtags' => ['#chania', 'crete']], 'en' => ['caption' => 'Sea bass']]],
    ])->assertCreated()->assertJsonPath('name', 'ALMYRA_20270512_IG_FEED_'.strtoupper($pillar).'_EL-EN_v1')->assertJsonPath('state', 'draft')
        ->assertJsonPath('captions.instagram.el.hashtags', ['#chania', 'crete'])->assertJsonPath('captions.instagram.en.caption', 'Sea bass');
    $this->withToken($plain)->postJson("/api/clients/{$this->a->id}/posts", ['platforms' => ['pinterest'], 'format' => 'feed'])->assertUnprocessable()->assertJsonValidationErrors(['platforms.0']);
    $id = $created->json('id');
    $this->withToken($plain)->getJson("/api/posts/{$id}")->assertOk()->assertJsonPath('brief', 'Sea bass')->assertJsonPath('url', route('posts.edit', $id));
    $this->withToken($plain)->getJson("/api/clients/{$this->a->id}/posts?state=draft&from=2027-05-01")->assertOk()->assertJsonCount(1);
    $this->withToken($plain)->getJson("/api/clients/{$this->a->id}/posts?state=published")->assertOk()->assertJsonCount(0);
    $this->withToken($plain)->getJson("/api/clients/{$this->a->id}/posts?state=gone")->assertUnprocessable();

    [, $limited] = ApiToken::issue('Blue only', $this->b->id, null);
    $this->withToken($limited)->getJson('/api/clients')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Blue Villa');
    $this->withToken($limited)->getJson("/api/clients/{$this->a->id}/posts")->assertForbidden();
    $this->withToken($limited)->getJson("/api/posts/{$id}")->assertForbidden();
    $this->withToken($limited)->getJson("/api/clients/{$this->b->id}/posts")->assertOk();

    $this->actingAs($this->staff)->delete('/staff/tokens/'.ApiToken::first()->id)->assertRedirect('/staff');
    $this->withToken($plain)->getJson('/api/clients')->assertUnauthorized();
});

test('the API lists the inbox and sends a reply the way the inbox does', function () {
    [, $plain] = ApiToken::issue('Bot', null, null);
    $account = $this->a->accounts()->create(['platform' => 'instagram', 'external_id' => 'ig', 'name' => '@a', 'access_token' => 't']);
    $comment = $this->a->comments()->create(['account_id' => $account->id, 'platform' => 'instagram', 'external_id' => 'c1', 'author' => 'maria', 'text' => 'Ώρες;', 'posted_at' => now(), 'sentiment' => 'neutral', 'intent' => 'question']);
    $this->a->comments()->create(['account_id' => $account->id, 'platform' => 'instagram', 'external_id' => 'c0', 'author' => 'x', 'text' => 'old', 'posted_at' => now()->subDay(), 'replied_at' => now()]);
    Http::fake(['graph.facebook.com/*/c1/replies' => Http::response(['id' => 'r1'])]);

    $this->withToken($plain)->getJson("/api/clients/{$this->a->id}/comments")->assertOk()->assertJsonCount(1)->assertJsonPath('0.text', 'Ώρες;')->assertJsonPath('0.intent', 'question');
    $this->withToken($plain)->getJson("/api/clients/{$this->a->id}/comments?open=0")->assertOk()->assertJsonCount(2);
    $this->withToken($plain)->postJson("/api/comments/{$comment->id}/reply", ['text' => 'Ανοιχτά 10:00–02:00'])->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/c1/replies') && $r['message'] === 'Ανοιχτά 10:00–02:00');
    expect($comment->fresh()->replied_at)->not->toBeNull();
});

test('webhooks in the profile get a signed JSON POST when a post is published or fails, only for the events they ask for', function () {
    Mail::fake();
    $this->a->update(['profile' => ['webhooks' => [
        ['url' => 'https://hooks.example.com/all', 'secret' => 's3cret'],
        ['url' => 'https://hooks.example.com/failed-only', 'events' => ['post.failed']],
    ]] + $this->a->profile]);
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $post = Post::factory()->create(['client_id' => $this->a->id, 'state' => PostState::Scheduled, 'scheduled_at' => now()]);

    Webhooks::post($post, 'post.published');
    Http::assertSentCount(1);
    Http::assertSent(function ($r) use ($post) {
        $body = json_decode($r->body(), true);

        return $r->url() === 'https://hooks.example.com/all' && $r->hasHeader('X-SocialOps-Event', 'post.published')
            && $r->hasHeader('X-SocialOps-Signature', hash_hmac('sha256', $r->body(), 's3cret'))
            && $body['event'] === 'post.published' && $body['post']['id'] === $post->id && $body['post']['name'] === $post->name;
    });

    (new PublishPost($post))->failed(new PublishFailed('Meta: down'));
    Http::assertSentCount(3);
    Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example.com/failed-only' && json_decode($r->body(), true)['post']['publish_error'] === 'Meta: down' && $r->hasHeader('X-SocialOps-Signature', hash_hmac('sha256', $r->body(), '')));
    expect($post->fresh()->state)->toBe(PostState::Failed);
});
