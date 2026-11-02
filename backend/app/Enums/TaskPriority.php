<?php

namespace App\Enums;

/** A task's priority («pri» in the app). */
enum TaskPriority: string
{
    use Concerns;

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'عالية',
            self::Medium => 'متوسطة',
            self::Low => 'منخفضة',
        };
    }
}
