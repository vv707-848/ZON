<?php

namespace App\Support\Knowledge;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * The text of a document uploaded to «مكتبة المعرفة», for staff to read over before saving it.
 *
 * - **Word (.docx):** read from the document itself, headings as `##` and list items as `-`: exact.
 * - **Text (.txt · .md):** as written; a file saved in the old Arabic Windows encoding (1256) is converted.
 * - **No PDF.** Measured on an Arabic PDF made from a Word file (LibreOffice): pdftotext, the best of three, got
 *   76% of the words right — every «لا» ligature comes out reversed («الإعلان» → «اإلعالن», «خلال» → «خالل») —
 *   pypdf jumbled the words and pdfplumber reversed them. The chat would repeat such words to users, so a PDF is
 *   refused with a way out: open it in Word and save it as .docx.
 */
final class DocumentText
{
    public const EXTENSIONS = ['docx', 'txt', 'md'];

    /** Invisible direction marks (LRE·RLE·PDF·LRO·RLO, the isolates, LRM·RLM) some editors leave in: not text. */
    private const MARKS = '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public static function extract(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'docx' => self::word($path),
            'pdf' => throw new RuntimeException('ملفات PDF العربية لا تُقرأ بدقة (تنقلب «لا» وأخواتها). افتحه في Word واحفظه .docx ثم ارفعه.'),
            'txt', 'md' => self::plain((string) file_get_contents($path)),
            default => throw new RuntimeException('نوع الملف غير مدعوم: '.implode(' · ', self::EXTENSIONS)),
        };
        $text = (string) preg_replace(self::MARKS, '', $text);
        $text = (string) preg_replace("/[ \t]+\n/u", "\n", str_replace("\r\n", "\n", $text));

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", $text));
    }

    private static function plain(string $bytes): string
    {
        $bytes = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : (string) iconv('CP1256', 'UTF-8//IGNORE', $bytes);   // mbstring has no 1256
    }

    private static function word(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException('ملف Word غير صالح.');
        }
        $zip->close();
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET);                    // no network, and PHP never expands external entities
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $lines = [];
        foreach ($xpath->query('//w:body//w:p') ?: [] as $paragraph) {
            assert($paragraph instanceof DOMElement);
            $text = '';
            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $paragraph) ?: [] as $node) {
                $text .= match ($node->localName) {
                    'tab' => "\t",
                    'br' => "\n",
                    default => $node->textContent,
                };
            }
            $style = (string) $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $paragraph);
            $prefix = match (true) {
                trim($text) === '' => '',
                preg_match('/^(Heading|Title)/i', $style) === 1 => '## ',
                $xpath->query('w:pPr/w:numPr', $paragraph)?->length > 0 => '- ',
                default => '',
            };
            $lines[] = $prefix.$text;
        }

        return implode("\n\n", $lines);
    }
}
