<?php

namespace App\Integrations\Nafath;

/** A started Nafath check: the app shows the random number, the person picks it in the Nafath app. */
final readonly class NafathChallenge
{
    public function __construct(public string $transactionId, public string $randomNumber) {}
}
