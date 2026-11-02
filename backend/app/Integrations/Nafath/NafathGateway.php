<?php

namespace App\Integrations\Nafath;

use App\Enums\NafathStatus;

/** Identity verification through Nafath (once per account, before the first publication). */
interface NafathGateway
{
    public function name(): string;

    public function start(string $nationalId): NafathChallenge;

    public function status(string $transactionId): NafathStatus;
}
