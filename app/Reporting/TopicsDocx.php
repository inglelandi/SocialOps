<?php

namespace App\Reporting;

use App\Models\Asset;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The θεματολογία (topic plan) of a content plan as a Word file for the client: the client and the month as a title, then
 * per post one bold line, a short description, why now, the shop's products it links, each name a link to its page, and
 * the post's photos as the calendar has them, plain, without boxes (Panos, 2026-11-10: "keep the report simpler", then
 * "Can you link the products also on the word report?", 2026-11-19: "show all the images that are gonna be in the post").
 * The titles and the links take the client's brand colour from the profile. Plain WordprocessingML written here, so
 * nothing to install on the server.
 */
class TopicsDocx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** The titles' colour when the profile names no brand colour: the app's dark grey. */
    public const DEFAULT_COLOR = '212121';

    /** A post's pictures go in as JPEGs no longer than this on their long side: the file stays small, the print sharp enough. */
    public const PICTURE_PX = 900;

    /** Width on the page: one picture alone, and each picture of a carousel (three to a row of the 16 cm text width), in cm. */
    public const PICTURE_CM = 8.0;

    public const CAROUSEL_PICTURE_CM = 5.0;

    private const EMU_PER_CM = 360000;

    /** "November posts 2026" in the client's language: the second title line and the file name. */
    public static function title(Report $report): string
    {
        $locale = $report->client->uiLocale();

        return __(':month posts :year', ['month' => self::month($report, $locale), 'year' => self::mainMonth($report)->year], $locale);
    }

    /** "Almyra - November posts 2026.docx", without the characters a download name cannot have ("Bar/Restaurant" has a slash). */
    public static function filename(Report $report): string
    {
        return trim(preg_replace('/[\/\\\\\x00-\x1F\x7F]+/u', '-', $report->client->name.' - '.self::title($report))).'.docx';
    }

    public static function build(Report $report): string
    {
        $client = $report->client;
        $locale = $client->uiLocale();

        $color = self::color($client->toProfileArray()['brand_color'] ?? '');
        $body = self::p(self::run($client->name, bold: true, size: 46, color: $color, font: 'Verdana').'<w:br/>'.self::run(self::title($report), bold: true, size: 34, color: $color, font: 'Verdana'), '<w:jc w:val="center"/>')
            .self::p('')
            .self::p(self::run(__('Suggested topics of :month for :client', ['month' => self::month($report, $locale), 'client' => $client->name], $locale), bold: true, size: 24), '<w:ind w:left="720"/>')
            .self::p('');

        $tiktok = 0;
        $links = []; // relationship id => address, for the products' links
        $media = []; // relationship id => ['target' => media/imageN.jpeg, 'bytes' => the JPEG], for the posts' pictures
        foreach (self::posts($report) as $post) {
            $date = $post['day'];
            $format = __(ucfirst(str_replace('_', ' ', $post['format'])), [], $locale);
            $tag = in_array('tiktok', $post['platforms'], true) ? ' #TikTok '.++$tiktok : '';
            $trend = filled($post['trend_signal'] ?? null) ? __('Trend:', [], $locale)." {$post['trend_signal']}. " : '';

            $whyNow = trim($trend.($post['why_now'] ?? ''));

            $body .= self::p(self::run($date->format('j/n').' · '.$post['title']." ({$format}){$tag}", bold: true, size: 24, color: $color))
                .self::p(self::run($post['angle']))
                .($whyNow !== '' ? self::p(self::run(__('Why now:', [], $locale).' ', bold: true).self::run($whyNow)) : '')
                .self::products($post['products'], $locale, $color, $links)
                .self::pictures($post['images'], $links, $media)
                .self::p('');
        }

        return self::zip($body, $links, $media);
    }

    /**
     * The post's photos as the calendar has them now (Panos, 2026-11-19: "on the word report it would be nice to show all the
     * images that are gonna be in the post"): one paragraph with the pictures in the post's order, 8 cm wide alone, 5 cm each
     * for a carousel so three sit in a row, each shrunk to a JPEG. Videos and a file GD cannot read are left out.
     *
     * @param  list<Asset>  $images
     * @param  array<string, string>  $links  the products' links so far, for the relationship ids
     * @param  array<string, array{target: string, bytes: string}>  $media  filled with each picture
     */
    private static function pictures(array $images, array $links, array &$media): string
    {
        $runs = [];
        $cm = count($images) > 1 ? self::CAROUSEL_PICTURE_CM : self::PICTURE_CM;
        foreach ($images as $image) {
            $bytes = $image->preview(self::PICTURE_PX);
            if ($bytes === null || ! ($size = @getimagesizefromstring($bytes)) || $size[0] < 1 || $size[1] < 1) {
                continue;
            }
            $n = count($media) + 1;
            $id = 'rId'.(count($links) + $n + 1); // rId1 is the styles; the links and the pictures share the numbering
            $media[$id] = ['target' => "media/image{$n}.jpeg", 'bytes' => $bytes];
            $cx = (int) round($cm * self::EMU_PER_CM);
            $runs[] = self::picture($id, $n, $cx, (int) round($cx * $size[1] / $size[0]));
        }

        return $runs === [] ? '' : self::p(implode('<w:r><w:t xml:space="preserve">  </w:t></w:r>', $runs));
    }

    /** One inline picture (DrawingML), $cx by $cy EMU, the image behind relationship $id. */
    private static function picture(string $id, int $n, int $cx, int $cy): string
    {
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            ."<wp:extent cx=\"{$cx}\" cy=\"{$cy}\"/><wp:docPr id=\"{$n}\" name=\"Picture {$n}\"/>"
            .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            ."<pic:nvPicPr><pic:cNvPr id=\"{$n}\" name=\"Picture {$n}\"/><pic:cNvPicPr/></pic:nvPicPr>"
            ."<pic:blipFill><a:blip r:embed=\"{$id}\"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>"
            ."<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$cx}\" cy=\"{$cy}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
            .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /**
     * The products a post links: "Product: name" with the name a link to the product's page, or "Products:" with one per
     * line. Only http(s) addresses become links (the feed keeps no other); a product without one is left out.
     *
     * @param  array<string, string>  $links  filled with the relationship id and address of each link
     */
    private static function products(array $products, string $locale, string $color, array &$links): string
    {
        $products = array_values(array_filter($products, fn ($product) => is_array($product) && filled($product['name'] ?? null) && preg_match('#^https?://#i', (string) ($product['url'] ?? ''))));
        if ($products === []) {
            return '';
        }
        $items = array_map(function (array $product) use ($color, &$links) {
            $id = 'rId'.(count($links) + 2); // rId1 is the styles
            $links[$id] = $product['url'];

            return "<w:hyperlink r:id=\"{$id}\" w:history=\"1\">".self::run($product['name'], color: $color, underline: true).'</w:hyperlink>';
        }, $products);
        $label = self::run(__(count($items) === 1 ? 'Product:' : 'Products:', [], $locale).' ', bold: true);

        return self::p(count($items) === 1 ? $label.$items[0] : $label.'<w:r><w:br/></w:r>'.implode('<w:r><w:br/></w:r>', $items));
    }

    /**
     * The plan's posts in date order, each with its `day`, `products` and `images` as the calendar has them now: the day its
     * post has (moved to a slot when it was filed, or by the team since), the products it links (changed with update-post
     * since, perhaps) and its photos in carousel order, else the planned date and products, and no pictures, for a post no
     * longer there.
     *
     * @return list<array<string, mixed>>
     */
    private static function posts(Report $report): array
    {
        $posts = $report->meta['posts'];
        $onCalendar = $report->client->posts()->with('assets')->whereIn('name', array_filter(array_column($posts, 'name')))->get(['id', 'name', 'scheduled_at', 'settings'])->keyBy('name');
        $posts = array_map(function (array $post) use ($onCalendar) {
            $now = $onCalendar[$post['name'] ?? ''] ?? null;

            return [
                'day' => CarbonImmutable::parse($now?->scheduled_at ?? $post['date'])->startOfDay(),
                'products' => $now ? ($now->settings['products'] ?? []) : ($post['products'] ?? []),
                'images' => $now ? $now->assets->where('kind', 'image')->values()->all() : [],
            ] + $post;
        }, $posts);
        usort($posts, fn (array $a, array $b) => $a['day'] <=> $b['day']);

        return $posts;
    }

    /** The month most of the posts fall in (the earlier one on a tie): a plan from 25 November to 24 December is December's. */
    private static function mainMonth(Report $report): CarbonImmutable
    {
        $months = array_count_values(array_map(fn (array $post) => $post['day']->format('Y-m'), self::posts($report)));
        arsort($months); // a stable sort, so on a tie the earlier month (posts are in date order) stays first

        return CarbonImmutable::parse(array_key_first($months).'-01');
    }

    private static function month(Report $report, string $locale): string
    {
        // "D MMMM" gives the genitive month in Greek (Νοεμβρίου), "MMMM" alone the nominative
        return Str::after(self::mainMonth($report)->locale($locale)->isoFormat('D MMMM'), ' ');
    }

    private static function run(string $text, bool $bold = false, ?int $size = null, ?string $color = null, ?string $font = null, bool $underline = false): string
    {
        $props = ($font ? "<w:rFonts w:ascii=\"{$font}\" w:hAnsi=\"{$font}\" w:cs=\"{$font}\"/>" : '').($bold ? '<w:b/>' : '').($color ? "<w:color w:val=\"{$color}\"/>" : '').($size ? "<w:sz w:val=\"{$size}\"/>" : '').($underline ? '<w:u w:val="single"/>' : '');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', str_replace("\r", '', $text)); // control characters XML does not allow: Word would not open the file
        $lines = array_map(fn (string $line) => '<w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</w:t>', explode("\n", $text));

        return "<w:r><w:rPr>{$props}</w:rPr>".implode('<w:br/>', $lines).'</w:r>';
    }

    private static function p(string $runs, string $props = ''): string
    {
        return "<w:p><w:pPr>{$props}</w:pPr>{$runs}</w:p>";
    }

    /** The profile's brand colour as Word writes it (rrggbb), the dark grey when it names none. */
    private static function color(string $brand): string
    {
        return preg_match('/^#?([0-9a-fA-F]{6})$/', trim($brand), $m) ? strtolower($m[1]) : self::DEFAULT_COLOR;
    }

    /**
     * The .docx package: content types, the relationships (the styles, then each link to a web page and each picture), the
     * pictures under word/media, default Arial 11pt, the A4 document.
     *
     * @param  array<string, string>  $links  relationship id => address
     * @param  array<string, array{target: string, bytes: string}>  $media  relationship id => the picture
     */
    private static function zip(string $body, array $links = [], array $media = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $drawing = 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
        $relationships = '';
        foreach ($links as $id => $url) {
            $relationships .= "<Relationship Id=\"{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink\" Target=\"".htmlspecialchars($url, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" TargetMode="External"/>';
        }
        foreach ($media as $id => $picture) {
            $relationships .= "<Relationship Id=\"{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"{$picture['target']}\"/>";
        }
        $parts = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpeg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.$relationships.'</Relationships>',
            'word/styles.xml' => $xml."<w:styles {$w}><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii=\"Arial\" w:hAnsi=\"Arial\" w:cs=\"Arial\"/><w:sz w:val=\"22\"/><w:szCs w:val=\"22\"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after=\"120\" w:line=\"276\" w:lineRule=\"auto\"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>",
            'word/document.xml' => $xml."<w:document {$w} xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\" {$drawing}><w:body>{$body}<w:sectPr><w:pgSz w:w=\"11906\" w:h=\"16838\"/><w:pgMar w:top=\"1440\" w:right=\"1440\" w:bottom=\"1440\" w:left=\"1440\" w:header=\"708\" w:footer=\"708\" w:gutter=\"0\"/></w:sectPr></w:body></w:document>",
        ];
        foreach ($media as $picture) {
            $parts['word/'.$picture['target']] = $picture['bytes'];
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
