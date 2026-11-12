<?php

namespace Tests\Unit;

use App\Support\Knowledge\DocumentText;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** An uploaded knowledge document's text: Word exactly (headings, lists), old Windows Arabic text converted, no PDF. */
class DocumentTextTest extends TestCase
{
    /** A .docx with the parts DocumentText reads: a heading, paragraphs (one with a tab), a bulleted item. */
    public static function word(string $path): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $p = fn (string $text, string $props = '') => "<w:p><w:pPr>{$props}</w:pPr><w:r><w:t xml:space=\"preserve\">{$text}</w:t></w:r></w:p>";
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document {$w}><w:body>"
            .$p('سياسة الباقات', '<w:pStyle w:val="Heading1"/>')
            .'<w:p><w:r><w:t>المدة</w:t><w:tab/><w:t>30 يوماً، ولا تُسترد.</w:t></w:r></w:p>'
            .$p('باقة الوسيط: 199 ريالاً.', '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>')
            .'</w:body></w:document>';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }

    public function test_a_word_document_comes_out_exactly_with_its_headings_and_lists(): void
    {
        $path = self::word(tempnam(sys_get_temp_dir(), 'kb').'.docx');

        $this->assertSame("## سياسة الباقات\n\nالمدة\t30 يوماً، ولا تُسترد.\n\n- باقة الوسيط: 199 ريالاً.", DocumentText::extract($path, 'docx'));
    }

    public function test_text_in_the_old_windows_arabic_encoding_is_converted_and_direction_marks_dropped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kb');
        file_put_contents($path, iconv('UTF-8', 'CP1256', "الشروط\r\n\r\n\r\n\r\nنص"));
        $this->assertSame("الشروط\n\nنص", DocumentText::extract($path, 'txt'));

        file_put_contents($path, "\xEF\xBB\xBF\u{202B}## عنوان\u{202C}\n");
        $this->assertSame('## عنوان', DocumentText::extract($path, 'md'));
    }

    public function test_a_pdf_is_refused_with_the_way_out(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.docx');
        DocumentText::extract(__FILE__, 'pdf');
    }
}
