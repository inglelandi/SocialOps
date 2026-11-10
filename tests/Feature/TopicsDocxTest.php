<?php

use App\Clients\ClientProfile;
use App\Models\Client;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

function planReport(Client $client)
{
    return $client->reports()->create(['kind' => 'plan', 'title' => 'Content batch 2026-11-04 to 2026-11-30', 'body' => 'sheet', 'meta' => [
        'window' => ['start' => '2026-11-04', 'end' => '2026-11-30'],
        'posts' => [
            ['date' => '2026-11-04', 'platforms' => ['instagram', 'facebook'], 'format' => 'carousel', 'pillar' => 'health', 'title' => 'Winter survival kit', 'angle' => "Cold & flu: what to have at home\nbefore the sneezing starts", 'trend_signal' => 'flu season searches', 'why_now' => 'First cold week', 'asset_brief' => '4 product photos', 'caption_brief' => 'Hook on the first slide', 'captions' => []],
            ['date' => '2026-11-06', 'platforms' => ['tiktok'], 'format' => 'tiktok_video', 'pillar' => 'team', 'title' => 'Pharmacist picks', 'angle' => '3 favourites', 'trend_signal' => null, 'why_now' => 'Autumn', 'asset_brief' => 'Film in the shop', 'caption_brief' => null],
        ],
    ]]);
}

test('the topics of a content plan download as a Word file in the client language', function () {
    $client = Client::factory()->create(['name' => 'Fuse Pharmacies']); // a fixed name: the XML escapes an apostrophe the faker may put in one
    $client->update(['profile' => ['languages' => ['el', 'en']] + $client->profile]);
    $report = planReport($client);
    $staff = $this->actingAs(User::factory()->create());

    $staff->get(route('reports.show', $report))->assertOk()->assertSee(route('reports.word', $report));

    $response = $staff->get(route('reports.word', $report))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($response->headers->get('Content-Disposition'))->toContain('.docx');

    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    unlink($path);

    expect(simplexml_load_string($xml))->not->toBeFalse();
    expect($xml)->toContain($client->name)->toContain('Αναρτήσεις Νοεμβρίου 2026')->toContain('Προτεινόμενες θεματολογίες Νοεμβρίου για '.$client->name)
        ->toContain('4/11 · Winter survival kit (Carousel)')->toContain('6/11 · Pharmacist picks (Βίντεο TikTok) #TikTok 1')
        ->toContain('Cold &amp; flu: what to have at home</w:t><w:br/><w:t xml:space="preserve">before the sneezing starts')
        ->toContain('Γιατί τώρα: </w:t></w:r><w:r><w:rPr></w:rPr><w:t xml:space="preserve">Τάση: flu season searches. First cold week')
        // simpler since 2026-11-10: no assets, caption brief or pillar, no shaded or bordered boxes, and the titles in dark grey without a brand colour
        ->not->toContain('Υλικό')->not->toContain('Λεζάντα')->not->toContain('Πυλώνας')->not->toContain('w:shd')->not->toContain('<w:tbl>')->not->toContain('Border')
        ->toContain('<w:color w:val="212121"/><w:sz w:val="46"/></w:rPr><w:t xml:space="preserve">Fuse Pharmacies')
        ->toContain('<w:color w:val="212121"/><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">4/11 · Winter survival kit (Carousel)');

    // the client's brand colour from the profile colours the titles and the post lines
    $client->update(['profile' => ['brand_color' => '#50C496'] + $client->profile]);
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $staff->get(route('reports.word', $report))->streamedContent());
    $zip->open($path);
    $branded = $zip->getFromName('word/document.xml');
    unlink($path);
    expect(substr_count($branded, '<w:color w:val="50c496"/>'))->toBe(2 + count($report->meta['posts']))->and($branded)->not->toContain('212121');
});

test('the client form keeps a brand colour as #rrggbb, takes a short or bare one, and refuses what is no colour', function () {
    expect(ClientProfile::color('#1E6FB8'))->toBe('#1e6fb8')->and(ClientProfile::color(' 1e6fb8 '))->toBe('#1e6fb8')
        ->and(ClientProfile::color('#1e6'))->toBe('#11ee66')->and(ClientProfile::color(''))->toBe('')
        ->and(ClientProfile::fromForm(['brand_color' => '#50C496'])['brand_color'])->toBe('#50c496');

    $client = Client::factory()->create(['name' => 'Fuse Pharmacies']);
    $staff = $this->actingAs(User::factory()->create());
    $staff->get(route('clients.edit', $client))->assertOk()->assertSee('Brand colour')->assertSee('name="p[brand_color]"', false);
    expect(fn () => ClientProfile::parse(Yaml::dump(['brand_color' => 'green'] + ClientProfile::template(), 4)))->toThrow(ValidationException::class);
});

test('only a content plan with its posts has a Word file', function () {
    $client = Client::factory()->create(['name' => 'Fuse Pharmacies']); // a fixed name: the XML escapes an apostrophe the faker may put in one
    $staff = $this->actingAs(User::factory()->create());

    $staff->get(route('reports.word', $client->reports()->create(['kind' => 'monthly', 'title' => 'Monthly report October 2026', 'body' => 'x'])))->assertNotFound();
    $staff->get(route('reports.word', $client->reports()->create(['kind' => 'plan', 'title' => 'Old plan', 'body' => 'x'])))->assertNotFound();
});
