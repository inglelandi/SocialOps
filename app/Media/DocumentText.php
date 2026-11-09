<?php

namespace App\Media;

use App\Models\ClientDocument;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * A document's bytes as plain text, for Claude to read: Word, Excel and PowerPoint files (the Office Open XML zips), plain
 * text, Markdown, CSV and JSON as they are. PDF and the old binary Office formats are not read: the reason is said in
 * plain words, in English, and kept on the file's row (the Files tab translates it).
 */
final class DocumentText
{
    public const PDF = 'PDF files are not read: open it in Google Docs or save it as Word';

    public const OLD_OFFICE = 'Old Office format: save it as .docx/.xlsx';

    public const UNREADABLE = 'This file type is not read';

    public const REASONS = [self::PDF, self::OLD_OFFICE, self::UNREADABLE];

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private const PLAIN = ['text/plain', 'text/markdown', 'text/x-markdown', 'text/csv', 'text/tab-separated-values', 'application/json'];

    private const PLAIN_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json'];

    private const OLD_OFFICE_MIMES = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint'];

    /** @throws RuntimeException with one of REASONS when the type is not read */
    public static function extract(string $mime, string $bytes, string $name): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $text = match (true) {
            $mime === self::DOCX || $extension === 'docx' => self::docx($bytes),
            $mime === self::XLSX || $extension === 'xlsx' => self::xlsx($bytes),
            $mime === self::PPTX || $extension === 'pptx' => self::pptx($bytes),
            in_array($mime, self::PLAIN, true) || str_starts_with($mime, 'text/') || in_array($extension, self::PLAIN_EXTENSIONS, true) => $bytes,
            $mime === 'application/pdf' || $extension === 'pdf' => throw new RuntimeException(self::PDF),
            in_array($mime, self::OLD_OFFICE_MIMES, true) || in_array($extension, ['doc', 'xls', 'ppt'], true) => throw new RuntimeException(self::OLD_OFFICE),
            default => throw new RuntimeException(self::UNREADABLE),
        };

        return self::clean($text);
    }

    /** UTF-8 without a BOM, Unix newlines, no runs of blank lines, trimmed, at most MAX_CHARS with a note when cut. */
    private static function clean(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) { // a Greek text file saved by an old Windows program
            $text = (function_exists('iconv') ? @iconv('CP1253', 'UTF-8//IGNORE', $text) : false) ?: mb_convert_encoding($text, 'UTF-8', 'ISO-8859-7');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = trim(preg_replace('/\n{3,}/', "\n\n", $text));
        if (mb_strlen($text) > ClientDocument::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, ClientDocument::MAX_CHARS))."\n[… cut at ".ClientDocument::MAX_CHARS.' characters]';
        }

        return $text;
    }

    /** word/document.xml: a paragraph per line, table cells tab-separated. */
    private static function docx(string $bytes): string
    {
        $xml = self::read($bytes, ['word/document.xml'])['word/document.xml'] ?? throw new RuntimeException(self::UNREADABLE);

        // a paragraph that ends a table cell ends the cell, not the line; an empty paragraph is a blank line
        return self::textOf(str_replace(['</w:p></w:tc>', '</w:tc>', '</w:tr>', '</w:p>', '<w:p/>', '<w:tab/>', '<w:br/>', '<w:cr/>'], ["\t", "\t", "\n", "\n", "\n", "\t", "\n", "\n"], $xml));
    }

    /** Every sheet as rows of tab-separated cells, under a "## sheet name" line when there are several. */
    private static function xlsx(string $bytes): string
    {
        $files = self::read($bytes);
        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            foreach (self::xml($files['xl/sharedStrings.xml'])->si ?? [] as $si) {
                $shared[] = self::texts($si);
            }
        }

        // the sheets in the workbook's order, each found through its relationship id (xl/_rels/workbook.xml.rels)
        $targets = [];
        if (isset($files['xl/_rels/workbook.xml.rels'])) {
            foreach (self::xml($files['xl/_rels/workbook.xml.rels'])->Relationship ?? [] as $rel) {
                $target = ltrim((string) $rel['Target'], '/');
                $targets[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : "xl/{$target}";
            }
        }
        $sheets = [];
        if (isset($files['xl/workbook.xml'])) {
            foreach (self::xml($files['xl/workbook.xml'])->sheets->sheet ?? [] as $sheet) {
                $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                if (isset($targets[$id], $files[$targets[$id]])) {
                    $sheets[(string) $sheet['name']] = $files[$targets[$id]];
                }
            }
        }
        if ($sheets === []) { // no workbook or relationships we understand: the sheet files in order
            $paths = array_filter(array_keys($files), fn (string $p) => preg_match('~^xl/worksheets/sheet\d+\.xml$~', $p));
            natsort($paths);
            foreach (array_values($paths) as $i => $path) {
                $sheets['Sheet '.($i + 1)] = $files[$path];
            }
        }

        $out = [];
        foreach ($sheets as $name => $xml) {
            $rows = [];
            foreach (self::xml($xml)->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $column = preg_match('/^([A-Z]+)/', (string) $c['r'], $m) ? self::column($m[1]) : count($cells);
                    $value = match ((string) $c['t']) {
                        's' => $shared[(int) $c->v] ?? '',
                        'inlineStr' => self::texts($c->is),
                        'b' => (string) $c->v === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $c->v,
                    };
                    $cells += array_fill(0, $column + 1, ''); // keep the columns aligned when a row has gaps
                    $cells[$column] = $value;
                }
                ksort($cells);
                $line = rtrim(implode("\t", $cells));
                if ($line !== '') {
                    $rows[] = $line;
                }
            }
            $out[] = (count($sheets) > 1 ? "## {$name}\n" : '').implode("\n", $rows);
        }

        return implode("\n\n", $out);
    }

    /** ppt/slides/slide*.xml in order: one slide per paragraph, its texts on lines. */
    private static function pptx(string $bytes): string
    {
        $files = self::read($bytes);
        $paths = array_filter(array_keys($files), fn (string $p) => preg_match('~^ppt/slides/slide\d+\.xml$~', $p));
        natsort($paths);
        $slides = [];
        foreach ($paths as $path) {
            $text = self::textOf(str_replace('</a:p>', "\n", $files[$path]));
            if ($text !== '') {
                $slides[] = $text;
            }
        }

        return implode("\n\n", $slides);
    }

    /** The named entries of a zip (every entry when $only is empty), from bytes: ZipArchive wants a file. */
    private static function read(string $bytes, array $only = []): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        try {
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException(self::UNREADABLE);
            }
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($only === [] || in_array($name, $only, true)) {
                    $files[$name] = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $files;
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        return simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA) ?: throw new RuntimeException(self::UNREADABLE);
    }

    /** The <t> texts under an element joined (a rich-text cell has several runs). */
    private static function texts(?SimpleXMLElement $element): string
    {
        return $element ? implode('', array_map(fn (SimpleXMLElement $t) => (string) $t, $element->xpath('.//*[local-name()="t"]') ?: [])) : '';
    }

    /** The text content of an XML fragment, entities decoded (an empty paragraph stays a blank line; clean() collapses runs). */
    private static function textOf(string $xml): string
    {
        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** A spreadsheet column letter as its index: A 0, Z 25, AA 26. */
    private static function column(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $letter) {
            $n = $n * 26 + ord($letter) - 64;
        }

        return $n - 1;
    }
}
