<?php

namespace App\Enums;

/** The app's four roles (customers.role). The developer's account is activated by a marketing contract. */
enum CustomerRole: string
{
    use Concerns;

    case Seeker = 'seeker';
    case Broker = 'broker';
    case Owner = 'owner';
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::Seeker => 'باحث',
            self::Broker => 'وسيط',
            self::Owner => 'مالك',
            self::Developer => 'مطوّر عقاري',
        };
    }

    /** Who may publish offers (backend doc §8): owners, brokers, developers — never a seeker. */
    public function canPublish(): bool
    {
        return $this !== self::Seeker;
    }
}
