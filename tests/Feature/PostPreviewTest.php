<?php

use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;

test('the post form shows a preview per ticked platform with the captions, placeholders filled, and the new-post form too', function () {
    $client = Client::factory()->create(['name' => 'Almyra Villas']);
    $client->update(['profile' => array_replace($client->profile, ['fields' => ['phone' => '+30 28210 12345']])]);
    $post = Post::factory()->create(['client_id' => $client->id, 'platforms' => ['instagram', 'facebook']]);
    $post->variants()->create(['platform' => 'instagram', 'lang' => 'el', 'caption' => 'Κράτηση: {{phone}}', 'hashtags' => ['chania']]);
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('posts.edit', $post))->assertOk()
        ->assertSee('data-preview-platform="instagram"', false)
        ->assertSee('<span data-preview="instagram.el.caption">Κράτηση: +30 28210 12345</span>', false)
        ->assertSee('<span class="text-blue-800" data-preview="instagram.el.hashtags">#chania</span>', false)
        ->assertSee('id="post-form"', false);

    $this->get(route('posts.create', $client))->assertOk()->assertSee('data-preview="facebook.en.caption"', false);
});

test('a post with several files previews as a carousel: every slide to swipe through, with the count, the arrows and the dots', function () {
    // Panos, 2026-11-19: "on posts that are carousels can you also show it on preview?"
    $client = Client::factory()->create(['name' => 'Dokos Home']);
    $post = Post::factory()->create(['client_id' => $client->id, 'platforms' => ['instagram'], 'format' => 'carousel']);
    $first = Asset::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_20261201_ANY_IMG_MISC_EL-EN_v1.png']);
    $second = Asset::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_20261201_ANY_IMG_MISC_EL-EN_v2.png']);
    $clip = Asset::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_20261201_ANY_VID_MISC_EL-EN_v1.mp4', 'kind' => 'video', 'mime' => 'video/mp4']);
    $this->actingAs(User::factory()->create());

    $post->assets()->attach([$second->id => ['position' => 0], $clip->id => ['position' => 1], $first->id => ['position' => 2]]);
    $html = $this->get(route('posts.edit', $post))->assertOk()->getContent();
    expect($html)->toContain('data-preview-media data-current="image:'.$second->id.',video:'.$clip->id.',image:'.$first->id.'"') // the carousel's order, not the ids'
        ->toContain('x-data="{ i: 0, n: 3 }" data-carousel')->toContain('>1/3</span>')->toContain('aria-label="Previous"')->toContain('aria-label="Next"')
        ->toContain('<video src="'.route('assets.download', $clip).'" controls playsinline')
        ->toMatch('#data-carousel>.*?<img src="'.preg_quote(route('assets.thumb', $second), '#').'".*?<video.*?<img src="'.preg_quote(route('assets.thumb', $first), '#').'"#s');

    // one file is shown plain (the script's own template still names the carousel), none says a photo is to follow
    $post->assets()->sync([$first->id => ['position' => 0]]);
    $html = $this->get(route('posts.edit', $post))->assertOk()->getContent();
    expect($html)->not->toContain('x-data="{ i: 0, n: 1 }"')->not->toContain('x-data="{ i: 0, n: 3 }"')
        ->toMatch('#data-preview-media data-current="image:'.$first->id.'">\s*<img src="'.preg_quote(route('assets.thumb', $first), '#').'"#');
    $post->assets()->sync([]);
    $this->get(route('posts.edit', $post))->assertOk()->assertSee('data-preview-media data-current=""', false)->assertSee('Photo or video to follow');
});
