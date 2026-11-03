<?php

namespace App\Integrations\Nafath;

/** Where one Nafath request stands, in Nafath's own words (WAITING · COMPLETED · REJECTED · EXPIRED). */
enum NafathAnswer: string
{
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
