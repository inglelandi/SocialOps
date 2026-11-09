<?php

use App\Models\Client;
use App\Planning\Prompt;

it('fills the caption prompt from the example profile and leaves no placeholder behind', function () {
    $profile = Client::factory()->create(['name' => 'Almyra Beach Lounge'])->toProfileArray();

    $text = Prompt::render('caption-draft', Prompt::profileVars($profile) + [
        'examples' => 'none yet',
        'post' => ['platforms' => ['instagram', 'facebook'], 'format' => 'reel', 'pillar' => 'food_drink', 'brief' => 'Sunset cocktails', 'asset_description' => 'Clip of the bar at sunset', 'photo' => 'none'],
    ]);

    expect($text)
        ->toContain('Almyra Beach Lounge')
        ->toContain('#chania, #crete')
        ->toContain('Platforms: instagram, facebook')
        ->toContain('Sunset cocktails')
        ->toContain('Client-specific banned phrases: none')
        ->toContain('Words and phrases the client likes, for when they fit (not in every post): none')
        ->toContain('What it offers: Sunbeds and umbrellas, the cocktail list')
        ->toContain('Extra information about the client: none')
        ->not->toContain('Used by the AI Drafting Service')
        ->not->toMatch('/\{\{\s*[\w.]+\s*\}\}/');

    // a profile saved before the About section existed still renders
    unset($profile['about'], $profile['voice']['words']);
    expect(Prompt::render('caption-draft', Prompt::profileVars($profile) + ['examples' => 'none yet', 'post' => ['platforms' => [], 'format' => 'feed', 'pillar' => 'place', 'brief' => '', 'asset_description' => '', 'photo' => 'none']]))
        ->toContain('What it is: none')->toContain('(not in every post): none');
});

it('renders the trend and plan prompts with key dates as bullet lines', function () {
    $profile = ['notes' => 'Family business since 1987.'] + Client::factory()->create()->toProfileArray();
    $vars = Prompt::profileVars($profile) + ['today' => '2027-05-01', 'window' => ['start' => '2027-05-02', 'end' => '2027-05-15'], 'platforms' => ['instagram'], 'count' => 3, 'holidays' => ['2027-05-02 Πάσχα (Orthodox Easter)'], 'plan_language' => 'Greek'];

    expect(Prompt::render('trend-research', $vars + ['max_searches' => 12]))
        ->toContain('- date: 2027-05-01, label: Season opening')
        ->toContain('Extra information about the client: Family business since 1987.')
        ->toContain('place 25%, food_drink 25%')
        ->toContain('written in Greek');
    expect(Prompt::render('content-plan', $vars + ['quota' => 'place: 2, offers: 1', 'instruction' => '', 'trend_brief' => 'BRIEF', 'recent' => [], 'upcoming_posts' => '', 'previous_posts' => '', 'reference' => '', 'designs' => '']))
        ->toContain('Posts per pillar (exact): place: 2, offers: 1')
        ->toContain('What the client wants from social media now: Fill the sunset tables')
        ->toContain("## Trend brief\nBRIEF")
        ->toContain("do not repeat these angles)\nnone")
        ->toContain("## The team's posts slideshow (posts prepared to go up)\n")
        ->toContain("write the caption briefs the same way.\nnone")
        ->toContain("the kind of post the client is used to.\nnone")
        ->toContain("say so in notes_for_reviewer.\nnone")
        ->toContain("## Past designs from the client's folder (described from the images)\n")
        ->toContain("keep the recurring formats and seasons.\nnone")
        ->toContain("Prefer what the client's files say over your own assumptions.");
});

it('refuses to render when a value is missing', function () {
    Prompt::render('caption-draft', []);
})->throws(InvalidArgumentException::class, 'no value for {{client.name}}');
