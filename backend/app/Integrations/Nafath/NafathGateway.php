<?php

namespace App\Integrations\Nafath;

/**
 * Identity verification through Nafath, once per account, before the first publication: the app shows a random
 * number, the person picks it in the Nafath app. Only the outcome is kept — never the national id.
 */
interface NafathGateway
{
    public function name(): string;

    public function start(string $nationalId): NafathChallenge;

    public function status(string $transactionId): NafathAnswer;
}
