<?php

namespace App\Integrations\Rega;

use App\Enums\ContractStatus;

/** The General Authority for Real Estate: FAL licences, advertising licences, owners' brokerage contracts. */
interface RegaGateway
{
    /** A broker's FAL licence. */
    public function checkFal(string $falNumber): LicenceCheck;

    /** An advertising licence typed by a broker (a listing) or a developer (a unit model of a ready project). */
    public function verifyAdLicence(string $number): LicenceCheck;

    /** Files the owner's brokerage contract; the owner approves it through Nafath. Returns REGA's reference. */
    public function submitBrokerageContract(string $deedNumber, string $ownerPhone): string;

    public function contractStatus(string $reference): ContractStatus;

    /** Issues the advertising licence of an approved contract (after the fee). */
    public function issueAdLicence(string $contractReference, string $deedNumber): LicenceCheck;
}
