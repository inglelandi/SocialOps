<?php

use App\Models\Client;
use App\Models\User;
use App\Posts\PostState;
use App\View\Icons;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/*
 * The interface's icons (Panos, 2026-11-09: "The site is missing icons. Put as many icons as you see fit in as many sections
 * as you think it fits"): Heroicons outline paths in App\View\Icons, drawn by <x-icon>. Under the tests an unknown name
 * throws, so every page the suite renders checks its icons; this file checks the names written in the views as well.
 */

test('every icon name written in the views and the post states exists', function () {
    $used = [];
    foreach (File::allFiles(resource_path('views')) as $file) {
        preg_match_all('~<x-icon\s+name="([a-z0-9-]+)"~', $file->getContents(), $direct);
        preg_match_all('~\s(?:icon|action-icon)="([a-z0-9-]+)"~', $file->getContents(), $attributes);
        foreach ([...$direct[1], ...$attributes[1]] as $name) {
            $used[$name][] = $file->getRelativePathname();
        }
    }
    foreach (PostState::cases() as $state) {
        $used[$state->icon()][] = PostState::class;
    }

    expect(count($used))->toBeGreaterThan(40);
    expect(array_keys(array_diff_key($used, Icons::PATHS)))->toBe([]);
});

test('an icon is an inline SVG in currentColor, hidden from screen readers, sized unless the class sizes it', function () {
    $default = Blade::render('<x-icon name="calendar-days" />');
    expect($default)->toContain('<svg')->toContain('class="h-5 w-5 shrink-0"')->toContain('stroke="currentColor"')->toContain('aria-hidden="true"')
        ->toContain(Icons::PATHS['calendar-days']);
    expect(Blade::render('<x-icon name="trash" class="h-4 w-4 text-red-600" />'))->toContain('h-4 w-4 text-red-600')->toContain('shrink-0')->not->toContain('h-5');
    expect(fn () => Blade::render('<x-icon name="no-such-icon" />'))->toThrow('No icon named no-such-icon'); // Blade wraps it in a ViewException
});

test('the navigation, the client tabs and the section cards carry their icons', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create(['name' => 'Dokos Home', 'slug' => 'dokos']);

    $html = $this->actingAs($user)->get(route('clients.edit', $client))->assertOk()->getContent();
    foreach (['home', 'calendar-days', 'device-phone-mobile', 'building-storefront', 'photo', 'folder-open', 'identification', 'trash', 'plus', 'microphone', 'sparkles'] as $name) {
        expect($html)->toContain(Icons::PATHS[$name]);
    }
    expect($html)->toContain('bg-brand-50 text-brand-600'); // a section card's icon tile
});
