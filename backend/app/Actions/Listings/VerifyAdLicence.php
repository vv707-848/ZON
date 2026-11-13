<?php

namespace App\Actions\Listings;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Integrations\Rega\LicenceCheck;
use App\Integrations\Rega\RegaGateway;
use App\Models\AdLicense;

/**
 * A broker's advertising licence, asked of REGA: valid there and not past its last day — or the field's message.
 * The same check before he fills the listing («تحقق»), when it is saved, and when he renews it.
 */
final class VerifyAdLicence
{
    public function __construct(private readonly RegaGateway $rega) {}

    public function __invoke(string $number): LicenceCheck
    {
        $check = $this->rega->verifyAdLicence($number);
        if (! $check->valid || $check->expiry()->toDateString() < AdLicense::today()) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['licence_number' => [
                $check->valid ? 'الرخصة منتهية لدى الهيئة. جدّدها لديها أولاً.' : 'رقم الترخيص الإعلاني غير صالح لدى الهيئة.',
            ]]]);
        }

        return $check;
    }
}
