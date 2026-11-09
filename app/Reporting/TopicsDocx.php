<?php

namespace App\Reporting;

use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The θεματολογία (topic plan) of a content plan as a Word file for the client, in the layout the team writes by hand:
 * the client and the month as a title, one bold line and a short description per post, then a shaded box per post with
 * why now, the assets and the caption brief. Plain WordprocessingML written here, so nothing to install on the server.
 */
class TopicsDocx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

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

        $body = self::p(self::run($client->name, bold: true, size: 46, color: '50c496', font: 'Verdana').'<w:br/>'.self::run(self::title($report), bold: true, size: 34, color: '50c496', font: 'Verdana'), '<w:jc w:val="center"/>')
            .self::p('')
            .self::p(self::run(__('Suggested topics of :month for :client', ['month' => self::month($report, $locale), 'client' => $client->name], $locale), bold: true, size: 24), '<w:ind w:left="720"/>')
            .self::p('');

        $tiktok = 0;
        foreach (self::posts($report) as $post) {
            $date = $post['day'];
            $format = __(ucfirst(str_replace('_', ' ', $post['format'])), [], $locale);
            $tag = in_array('tiktok', $post['platforms'], true) ? ' #TikTok '.++$tiktok : '';
            $trend = filled($post['trend_signal'] ?? null) ? __('Trend:', [], $locale)." {$post['trend_signal']}. " : '';

            $box = [self::p(self::run($date->format('d/m').' – '.$post['title']." ({$format})", bold: true))];
            foreach ([
                __('Why now:', [], $locale) => trim($trend.($post['why_now'] ?? '')),
                __('Assets:', [], $locale) => $post['asset_brief'] ?? '',
                __('Caption:', [], $locale) => $post['caption_brief'] ?? '',
                __('Pillar:', [], $locale) => $post['pillar'] ?? '',
            ] as $label => $text) {
                if (filled($text)) {
                    $box[] = self::p(self::run($label.' ', bold: true).self::run($text));
                }
            }

            $body .= self::p(self::run($date->format('j/n').' · '.$post['title']." ({$format}){$tag}", bold: true, size: 24))
                .self::p(self::run($post['angle']))
                .self::box($box)
                .self::p('');
        }

        return self::zip($body);
    }

    /**
     * The plan's posts in date order, each with its `day`: the day its post has on the calendar now (moved to a slot when
     * it was filed, or by the team since), else the planned date for a post no longer there.
     *
     * @return list<array<string, mixed>>
     */
    private static function posts(Report $report): array
    {
        $posts = $report->meta['posts'];
        $onCalendar = $report->client->posts()->whereIn('name', array_filter(array_column($posts, 'name')))->whereNotNull('scheduled_at')->pluck('scheduled_at', 'name');
        $posts = array_map(fn (array $post) => $post + ['day' => CarbonImmutable::parse($onCalendar[$post['name'] ?? ''] ?? $post['date'])->startOfDay()], $posts);
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

    private static function run(string $text, bool $bold = false, ?int $size = null, ?string $color = null, ?string $font = null): string
    {
        $props = ($font ? "<w:rFonts w:ascii=\"{$font}\" w:hAnsi=\"{$font}\" w:cs=\"{$font}\"/>" : '').($bold ? '<w:b/>' : '').($color ? "<w:color w:val=\"{$color}\"/>" : '').($size ? "<w:sz w:val=\"{$size}\"/>" : '');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', str_replace("\r", '', $text)); // control characters XML does not allow: Word would not open the file
        $lines = array_map(fn (string $line) => '<w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</w:t>', explode("\n", $text));

        return "<w:r><w:rPr>{$props}</w:rPr>".implode('<w:br/>', $lines).'</w:r>';
    }

    private static function p(string $runs, string $props = ''): string
    {
        return "<w:p><w:pPr>{$props}</w:pPr>{$runs}</w:p>";
    }

    /** One shaded, bordered cell, like the concept boxes in the team's files. */
    private static function box(array $paragraphs): string
    {
        $borders = implode('', array_map(fn ($side) => "<w:{$side} w:val=\"single\" w:sz=\"8\" w:color=\"000000\"/>", ['top', 'left', 'bottom', 'right']));
        $margins = implode('', array_map(fn ($side) => "<w:{$side} w:w=\"100\" w:type=\"dxa\"/>", ['top', 'left', 'bottom', 'right']));

        return '<w:tbl><w:tblPr><w:tblW w:w="9026" w:type="dxa"/><w:tblBorders>'.$borders.'</w:tblBorders><w:tblCellMar>'.$margins.'</w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="9026"/></w:tblGrid>'
            .'<w:tr><w:tc><w:tcPr><w:tcW w:w="9026" w:type="dxa"/><w:shd w:val="clear" w:fill="d9ead3"/></w:tcPr>'.implode('', $paragraphs).'</w:tc></w:tr></w:tbl>';
    }

    /** The .docx package: content types, the relationships, default Arial 11pt, the A4 document. */
    private static function zip(string $body): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $parts = [
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/styles.xml' => $xml."<w:styles {$w}><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii=\"Arial\" w:hAnsi=\"Arial\" w:cs=\"Arial\"/><w:sz w:val=\"22\"/><w:szCs w:val=\"22\"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after=\"120\" w:line=\"276\" w:lineRule=\"auto\"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>",
            'word/document.xml' => $xml."<w:document {$w}><w:body>{$body}<w:sectPr><w:pgSz w:w=\"11906\" w:h=\"16838\"/><w:pgMar w:top=\"1440\" w:right=\"1440\" w:bottom=\"1440\" w:left=\"1440\" w:header=\"708\" w:footer=\"708\" w:gutter=\"0\"/></w:sectPr></w:body></w:document>",
        ];

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
