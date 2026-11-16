<?php

namespace Tests\Unit;

use App\Jobs\CleanListingText;
use PHPUnit\Framework\TestCase;

/** A feature is kept only when the advertiser's own text states it. */
class StatedFeaturesTest extends TestCase
{
    public function test_only_the_features_the_text_states_are_kept(): void
    {
        $text = 'شقة بالدور الثاني، المطبخ راكب، فيها مصعد وموقف خاص. بدون مسبح، ولا يوجد غرفة خادمة. قريبة من المسجد';
        $suggested = ['مطبخ راكب', 'مصعد', 'موقف خاص', 'مسبح', 'غرفة خادمة', 'حديقة', 'تشطيب فاخر', 'قريبة من المسجد'];

        $this->assertSame(['مطبخ راكب', 'مصعد', 'موقف خاص', 'قريبة من المسجد'], CleanListingText::said($suggested, $text));
        $this->assertSame([], CleanListingText::said(['مصعد'], ''));
    }
}
