<?php

namespace App\Enums;

/** What the search index holds. */
enum IndexEntity: string
{
    use Concerns;

    case Listing = 'listing';
    case ProjectUnit = 'project_unit';
    case Request = 'request';
    case KbDocument = 'kb_document';

    public function label(): string
    {
        return match ($this) {
            self::Listing => 'عرض',
            self::ProjectUnit => 'نموذج وحدة',
            self::Request => 'طلب',
            self::KbDocument => 'مستند معرفة',
        };
    }
}
