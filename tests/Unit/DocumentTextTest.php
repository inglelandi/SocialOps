<?php

use App\Media\DocumentText;
use App\Models\ClientDocument;

/** An Office Open XML file (docx, xlsx, pptx) is a zip of XML parts: the bytes of one with these parts. */
function officeZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'office');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('a Word file reads as one paragraph per line with table cells tab-separated', function () {
    $docx = officeZip(['word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        .'<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Θεματολογία Νοεμβρίου</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t xml:space="preserve">Post 1: </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>Sunset &amp; cocktails</w:t></w:r></w:p>'
        .'<w:p/>'
        .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Date</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Topic</w:t></w:r></w:p></w:tc></w:tr>'
        .'<w:tr><w:tc><w:p><w:r><w:t>12-11</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Full moon</w:t></w:r><w:r><w:br/><w:t>party</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        .'</w:body></w:document>']);

    expect(DocumentText::extract('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx, 'plan.docx'))
        ->toBe("Θεματολογία Νοεμβρίου\nPost 1: Sunset & cocktails\n\nDate\tTopic\n12-11\tFull moon\nparty");
});

test('an Excel file reads every sheet as tab-separated rows, shared strings resolved, under the sheet name', function () {
    $xlsx = officeZip([
        'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Πλάνο" sheetId="1" r:id="rId1"/><sheet name="Prices" sheetId="2" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet1.xml"/></Relationships>',
        'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3"><si><t>Date</t></si><si><t>Topic</t></si><si><r><t xml:space="preserve">Sunset </t></r><r><rPr><b/></rPr><t>cocktails</t></r></si></sst>',
        'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2"><v>45000</v></c><c r="C2" t="s"><v>2</v></c></row><row r="3"/></sheetData></worksheet>',
        'xl/worksheets/sheet2.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Mojito</t></is></c><c r="B1"><v>9</v></c><c r="C1" t="b"><v>1</v></c></row></sheetData></worksheet>',
    ]);

    expect(DocumentText::extract('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx, 'prices.xlsx'))
        ->toBe("## Πλάνο\nDate\tTopic\n45000\t\tSunset cocktails\n\n## Prices\nMojito\t9\tTRUE");
});

test('a PowerPoint file reads one slide per paragraph, in slide order', function () {
    $slide = fn (string ...$paragraphs) => '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody>'
        .implode('', array_map(fn ($p) => "<a:p><a:r><a:rPr lang=\"en-US\"/><a:t>{$p}</a:t></a:r></a:p>", $paragraphs)).'</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
    $pptx = officeZip([
        'ppt/slides/slide10.xml' => $slide('Slide ten'),
        'ppt/slides/slide1.xml' => $slide('Welcome to Almyra', 'Season 2027'),
        'ppt/slides/slide2.xml' => $slide('Our menu'),
        'ppt/slides/_rels/slide1.xml.rels' => '<Relationships/>',
    ]);

    expect(DocumentText::extract('application/vnd.openxmlformats-officedocument.presentationml.presentation', $pptx, 'deck.pptx'))
        ->toBe("Welcome to Almyra\nSeason 2027\n\nOur menu\n\nSlide ten");
});

test('plain text, Markdown, CSV and JSON come back as they are, without a BOM, Windows newlines or runs of blank lines', function () {
    expect(DocumentText::extract('text/csv', "\xEF\xBB\xBFname,price\r\nMojito,9\r\n\r\n\r\n\r\nNegroni,10  \r\n", 'prices.csv'))->toBe("name,price\nMojito,9\n\nNegroni,10")
        ->and(DocumentText::extract('application/octet-stream', "# Brief\n\nTone: warm", 'brief.md'))->toBe("# Brief\n\nTone: warm")
        ->and(DocumentText::extract('application/json', '{"a":1}', 'x.json'))->toBe('{"a":1}')
        ->and(DocumentText::extract('text/plain', "\xC3\xE5\xE9\xDC \xF3\xE1\xF2", 'old.txt'))->toBe('Γειά σας'); // a Greek Windows-1253 text file
});

test('PDF, the old Office formats and unknown types are refused with a reason in plain words', function () {
    expect(fn () => DocumentText::extract('application/pdf', '%PDF-1.4', 'menu.pdf'))->toThrow(RuntimeException::class, DocumentText::PDF)
        ->and(fn () => DocumentText::extract('application/octet-stream', '%PDF-1.4', 'menu.PDF'))->toThrow(RuntimeException::class, DocumentText::PDF)
        ->and(fn () => DocumentText::extract('application/msword', "\xD0\xCF\x11\xE0", 'old.doc'))->toThrow(RuntimeException::class, DocumentText::OLD_OFFICE)
        ->and(fn () => DocumentText::extract('application/octet-stream', 'x', 'old.xls'))->toThrow(RuntimeException::class, DocumentText::OLD_OFFICE)
        ->and(fn () => DocumentText::extract('application/zip', 'PK', 'photos.zip'))->toThrow(RuntimeException::class, DocumentText::UNREADABLE)
        ->and(fn () => DocumentText::extract('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'not a zip', 'broken.docx'))->toThrow(RuntimeException::class, DocumentText::UNREADABLE);
});

test('the text is cut at 80000 characters with a note', function () {
    $text = DocumentText::extract('text/plain', str_repeat('α', ClientDocument::MAX_CHARS + 500), 'long.txt');

    expect(mb_strlen($text))->toBe(ClientDocument::MAX_CHARS + mb_strlen("\n[… cut at 80000 characters]"))
        ->and($text)->toEndWith("α\n[… cut at 80000 characters]")
        ->and(DocumentText::extract('text/plain', str_repeat('α', ClientDocument::MAX_CHARS), 'exact.txt'))->not->toContain('cut at');
});
