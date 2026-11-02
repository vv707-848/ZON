<?php

namespace App\Integrations\Rega;

use App\Enums\ContractStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * SIMULATION of REGA until it grants API access. Deterministic rules, so the app can try every branch:
 * - a number of 6–12 digits is valid, unless it ends with 0000 (refused);
 * - a deed number ending with 0000 gets its contract rejected; any other is approved at the first check;
 * - links point to sim.zon.local, never to a government domain.
 */
class FakeRegaGateway implements RegaGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function checkFal(string $falNumber): LicenceCheck
    {
        return $this->check($falNumber);
    }

    public function verifyAdLicence(string $number): LicenceCheck
    {
        $check = $this->check($number);

        return $check->valid ? new LicenceCheck(true, $number, $check->expiresAt, "https://sim.zon.local/rega/ad/{$number}") : $check;
    }

    public function submitBrokerageContract(string $deedNumber, string $ownerPhone): string
    {
        return 'SIM-BC-'.(str_ends_with($deedNumber, '0000') ? 'R' : 'A').'-'.Str::upper(Str::random(8));
    }

    public function contractStatus(string $reference): ContractStatus
    {
        return str_starts_with($reference, 'SIM-BC-R-') ? ContractStatus::Rejected : ContractStatus::Approved;
    }

    public function issueAdLicence(string $contractReference, string $deedNumber): LicenceCheck
    {
        $number = '71'.str_pad((string) (crc32($contractReference) % 100_000_000), 8, '0', STR_PAD_LEFT);

        return new LicenceCheck(true, $number, CarbonImmutable::today()->addYear(), "https://sim.zon.local/rega/ad/{$number}");
    }

    private function check(string $number): LicenceCheck
    {
        if (preg_match('/^\d{6,12}$/', $number) !== 1) {
            return new LicenceCheck(false, $number, reason: 'format');
        }
        if (str_ends_with($number, '0000')) {
            return new LicenceCheck(false, $number, reason: 'not_found');
        }

        return new LicenceCheck(true, $number, CarbonImmutable::today()->addYear());
    }
}
