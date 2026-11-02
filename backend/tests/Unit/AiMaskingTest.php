<?php

namespace Tests\Unit;

use App\Ai\Masking\AiMasking;
use PHPUnit\Framework\TestCase;

/** What leaves for the AI node carries no way to reach a person — and keeps the numbers that describe a property. */
class AiMaskingTest extends TestCase
{
    public function test_contacts_in_every_usual_writing_are_masked(): void
    {
        foreach ([
            'كلمني 0551234567', 'كلمني 055 123 4567', 'كلمني 055-123-4567', 'كلمني 055.123.4567', 'كلمني (055) 123 4567',
            'كلمني +966 55 123 4567', 'كلمني 00966551234567', 'كلمني 966551234567', 'كلمني 551234567', 'كلمني ٠٥٥١٢٣٤٥٦٧',
            'الهاتف 011 234 5678', 'الموحد 9200 12345',
        ] as $text) {
            $masked = AiMasking::text($text);
            $this->assertMatchesRegularExpression('/^\S+ \[رقم\]$/u', $masked, "«{$text}» → «{$masked}»");
        }
        $this->assertSame('[بريد] · [رابط] · [حساب]', AiMasking::text('a.b@zon.sa · https://wa.me/123 · SA44 2000 0001 2345 6789 1234'));
        $this->assertSame('اتصل بالعميل', AiMasking::text('اتصل بخالد', ['خالد']));
    }

    public function test_the_numbers_of_a_listing_stay(): void
    {
        $text = 'شقة 3 غرف و2 دورات، مساحة 175 م، السعر 880,000 ريال، عمر 4 سنوات، الدور 2، بُنيت 2021-05-10، الصك 1500000';
        $this->assertSame($text, AiMasking::text($text));
    }
}
