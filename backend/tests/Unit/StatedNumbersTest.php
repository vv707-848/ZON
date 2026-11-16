<?php

namespace Tests\Unit;

use App\Ai\Retrieval\StatedNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a description states in numbers is read only when it says it plainly and once. */
class StatedNumbersTest extends TestCase
{
    #[DataProvider('texts')]
    public function test_a_text_states(string $text, ?float $price, ?int $bedrooms, ?float $area): void
    {
        $this->assertSame(['price' => $price, 'bedrooms' => $bedrooms, 'area' => $area], StatedNumbers::in($text));
    }

    /** @return array<string, array{string, ?float, ?int, ?float}> */
    public static function texts(): array
    {
        return [
            'a seeker' => ['شقة 4 غرف بحدود 900 ألف، مساحة 180', 900000.0, 4, 180.0],
            'a broker\'s offer' => ['عندي شقة للبيع في الجلوية، 4 غرف، المطبخ مركب، السعر 600,000 ريال', 600000.0, 4, null],
            'Arabic digits' => ['٣ غرف، ٩٥٠ الف، ٢٠٠ م²', 950000.0, 3, 200.0],
            'a range written once' => ['أبغى فيلا من 500 إلى 700 ألف', 700000.0, null, null],
            'a range of two amounts: its top' => ['بين 1.2 مليون و1.5 مليون ثلاث غرف', 1500000.0, 3, null],
            'a half and a dual in words' => ['ميزانيتي مليون ونص وأبي غرفتين', 1500000.0, 2, null],
            'two millions' => ['فيلا ثلاث ادوار مليونين', 2000000.0, null, null],
            'land' => ['أرض مساحتها 900 متر مربع بسعر 360000', 360000.0, null, 900.0],
            'nothing stated' => ['مدخل خاص ومصعد', null, null, null],
            'a street and a phone number' => ['شقة قريبة من المسجد على شارع 16 متر للتواصل 0551234567', null, null, null],
            'two prices that are no range' => ['السعر 600 ألف قريب من مسجد، كان 650 ألف', null, null, null],
            'two room counts' => ['3 غرف او 4 غرف', null, null, null],
            'a down payment is not a price' => ['معي مقدم 100 ألف وأبغى 3 غرف', null, 3, null],
            'a salary is not a price' => ['راتبي 12000 وأدور شقة', null, null, null],
        ];
    }
}
