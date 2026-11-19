<?php

use App\Clients\ClientProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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

test('each post lists the shop products it links, each name a link to its page, as the calendar post has them now', function () {
    $client = Client::factory()->create(['name' => 'Dokos Home']);
    $client->update(['profile' => ['languages' => ['el', 'en'], 'brand_color' => '#1E6FB8'] + $client->profile]);
    $sheet = ['id' => 1, 'name' => 'Σεντόνι Inventor 160×200', 'url' => 'https://dokoshome.gr/p/sentoni?size=160&color=white', 'price' => '39,90'];
    $duvet = ['id' => 2, 'name' => 'Πάπλωμα Winter μονό', 'url' => 'https://dokoshome.gr/p/paploma', 'price' => '59,00'];
    $report = $client->reports()->create(['kind' => 'plan', 'title' => 'Content batch', 'body' => 'sheet', 'meta' => ['window' => ['start' => '2026-12-01', 'end' => '2026-12-31'], 'posts' => [
        ['name' => 'DOKOS_ONE', 'date' => '2026-12-02', 'platforms' => ['facebook'], 'format' => 'feed', 'title' => 'Winter bedding', 'angle' => 'Warm nights', 'why_now' => 'First frost', 'products' => [$sheet]],
        ['name' => 'DOKOS_TWO', 'date' => '2026-12-05', 'platforms' => ['instagram'], 'format' => 'carousel', 'title' => 'Gift guide', 'angle' => 'Gifts for the home', 'why_now' => 'Christmas', 'products' => [$sheet]],
        ['name' => 'DOKOS_GONE', 'date' => '2026-12-09', 'platforms' => ['instagram'], 'format' => 'feed', 'title' => 'Sale', 'angle' => 'Sale', 'why_now' => 'Sale', 'products' => [$duvet, ['id' => 3, 'name' => 'Odd', 'url' => 'javascript:alert(1)']]],
        ['name' => 'DOKOS_NONE', 'date' => '2026-12-12', 'platforms' => ['instagram'], 'format' => 'feed', 'title' => 'Our shop', 'angle' => 'The shop', 'why_now' => 'Weekend'],
    ]]]);
    // on the calendar: the first keeps its product, the second was given two since, the third was deleted for good
    Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_ONE', 'scheduled_at' => '2026-12-02 10:00', 'settings' => ['products' => [$sheet]]]);
    Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_TWO', 'scheduled_at' => '2026-12-05 10:00', 'settings' => ['products' => [$duvet, $sheet]]]);
    Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_NONE', 'scheduled_at' => '2026-12-12 10:00']);

    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $this->actingAs(User::factory()->create())->get(route('reports.word', $report))->assertOk()->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    [$xml, $rels] = [$zip->getFromName('word/document.xml'), $zip->getFromName('word/_rels/document.xml.rels')];
    unlink($path);

    expect(simplexml_load_string($xml))->not->toBeFalse()->and(simplexml_load_string($rels))->not->toBeFalse();
    $link = fn (string $id, string $name) => '<w:hyperlink r:id="'.$id.'" w:history="1"><w:r><w:rPr><w:color w:val="1e6fb8"/><w:u w:val="single"/></w:rPr><w:t xml:space="preserve">'.$name.'</w:t></w:r></w:hyperlink>';
    expect($xml)->toContain('xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"')
        ->toContain('Προϊόν: </w:t></w:r>'.$link('rId2', 'Σεντόνι Inventor 160×200'))
        ->toContain('Προϊόντα: </w:t></w:r><w:r><w:br/></w:r>'.$link('rId3', 'Πάπλωμα Winter μονό').'<w:r><w:br/></w:r>'.$link('rId4', 'Σεντόνι Inventor 160×200'))
        ->toContain('Προϊόν: </w:t></w:r>'.$link('rId5', 'Πάπλωμα Winter μονό')) // a post no longer on the calendar keeps the plan's products
        ->not->toContain('Odd')->not->toContain('javascript')
        ->and(substr_count($xml, 'Προϊόν'))->toBe(3); // the post without products has no line
    expect($rels)->toContain('<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>')
        ->toContain('<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://dokoshome.gr/p/sentoni?size=160&amp;color=white" TargetMode="External"/>')
        ->toContain('Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://dokoshome.gr/p/paploma" TargetMode="External"/>')
        ->not->toContain('rId6');
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

test('each post shows its photos as the calendar has them, one wide alone and three to a row for a carousel, videos left out', function () {
    Storage::fake('local');
    $client = Client::factory()->create(['name' => 'Dokos Home']);
    $report = $client->reports()->create(['kind' => 'plan', 'title' => 'Content batch', 'body' => 'sheet', 'meta' => ['window' => ['start' => '2026-12-01', 'end' => '2026-12-31'], 'posts' => [
        ['name' => 'DOKOS_ONE', 'date' => '2026-12-02', 'platforms' => ['facebook'], 'format' => 'feed', 'title' => 'Winter bedding', 'angle' => 'Warm nights', 'why_now' => 'First frost'],
        ['name' => 'DOKOS_TWO', 'date' => '2026-12-05', 'platforms' => ['instagram'], 'format' => 'carousel', 'title' => 'Gift guide', 'angle' => 'Gifts for the home', 'why_now' => 'Christmas', 'products' => [['id' => 1, 'name' => 'Σεντόνι', 'url' => 'https://dokoshome.gr/p/sentoni']]],
        ['name' => 'DOKOS_NONE', 'date' => '2026-12-12', 'platforms' => ['instagram'], 'format' => 'feed', 'title' => 'Our shop', 'angle' => 'The shop', 'why_now' => 'Weekend'],
    ]]]);
    $photo = function (string $name, int $w, int $h) use ($client): Asset {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        Storage::disk('local')->put("clients/{$client->slug}/inbox/{$name}", ob_get_clean());

        return Asset::factory()->create(['client_id' => $client->id, 'name' => $name, 'original_name' => $name, 'path' => "clients/{$client->slug}/inbox/{$name}", 'mime' => 'image/png', 'kind' => 'image']);
    };
    $one = Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_ONE', 'scheduled_at' => '2026-12-02 10:00']);
    $one->assets()->attach($photo('DOKOS_20261201_ANY_IMG_MISC_EL-EN_v1.png', 1200, 1500)->id, ['position' => 0]); // portrait 4:5
    $two = Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_TWO', 'scheduled_at' => '2026-12-05 10:00', 'settings' => ['products' => [['id' => 1, 'name' => 'Σεντόνι', 'url' => 'https://dokoshome.gr/p/sentoni']]]]);
    $two->assets()->attach([
        $photo('DOKOS_20261201_ANY_IMG_MISC_EL-EN_v2.png', 1080, 1080)->id => ['position' => 0],
        Asset::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_20261201_ANY_VID_MISC_EL-EN_v1.mp4', 'kind' => 'video', 'mime' => 'video/mp4'])->id => ['position' => 1],
        $photo('DOKOS_20261201_ANY_IMG_MISC_EL-EN_v3.png', 1080, 1350)->id => ['position' => 2],
    ]);
    Post::factory()->create(['client_id' => $client->id, 'name' => 'DOKOS_NONE', 'scheduled_at' => '2026-12-12 10:00']);

    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $this->actingAs(User::factory()->create())->get(route('reports.word', $report))->assertOk()->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);
    [$xml, $rels, $types] = [$zip->getFromName('word/document.xml'), $zip->getFromName('word/_rels/document.xml.rels'), $zip->getFromName('[Content_Types].xml')];
    $pictures = array_map(fn (int $n) => $zip->getFromName("word/media/image{$n}.jpeg"), [1, 2, 3]);
    expect($zip->getFromName('word/media/image4.jpeg'))->toBeFalse(); // the video is not a picture
    unlink($path);

    expect(simplexml_load_string($xml))->not->toBeFalse()->and(simplexml_load_string($rels))->not->toBeFalse()
        ->and(substr_count($xml, '<w:drawing>'))->toBe(3)
        ->and($types)->toContain('<Default Extension="jpeg" ContentType="image/jpeg"/>');
    // the lone photo 8 cm wide, 10 cm tall (4:5); the carousel's two 5 cm wide, next to each other in one paragraph, after the product line
    expect($xml)->toContain('<wp:extent cx="2880000" cy="3600000"/>')
        ->toContain('<wp:extent cx="1800000" cy="1800000"/>')->toContain('<wp:extent cx="1800000" cy="2250000"/>')
        ->toMatch('#Προϊόν:.*?</w:p><w:p><w:pPr></w:pPr><w:r><w:drawing>.*?cx="1800000" cy="1800000".*?</w:drawing></w:r><w:r><w:t xml:space="preserve">  </w:t></w:r><w:r><w:drawing>.*?cx="1800000" cy="2250000".*?</w:drawing></w:r></w:p>#s');
    // every picture is a JPEG no longer than 900 px, shrunk from the file, and its relationship points at it
    foreach ($pictures as $n => $bytes) {
        $size = getimagesizefromstring($bytes);
        expect($size['mime'])->toBe('image/jpeg')->and(max($size[0], $size[1]))->toBeLessThanOrEqual(900);
        expect($rels)->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image'.($n + 1).'.jpeg"/>');
    }
    preg_match_all('/r:embed="(rId\d+)"/', $xml, $embeds);
    foreach ($embeds[1] as $id) {
        expect($rels)->toContain('Id="'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"');
    }
    expect($rels)->toContain('Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'); // the product link keeps its own id
});

test('only a content plan with its posts has a Word file', function () {
    $client = Client::factory()->create(['name' => 'Fuse Pharmacies']); // a fixed name: the XML escapes an apostrophe the faker may put in one
    $staff = $this->actingAs(User::factory()->create());

    $staff->get(route('reports.word', $client->reports()->create(['kind' => 'monthly', 'title' => 'Monthly report October 2026', 'body' => 'x'])))->assertNotFound();
    $staff->get(route('reports.word', $client->reports()->create(['kind' => 'plan', 'title' => 'Old plan', 'body' => 'x'])))->assertNotFound();
});
