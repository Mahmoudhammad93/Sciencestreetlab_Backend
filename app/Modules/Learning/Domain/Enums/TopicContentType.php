<?php

declare(strict_types=1);

namespace App\Modules\Learning\Domain\Enums;

enum TopicContentType: string
{
    case Video = 'video';
    case Text = 'text';
    case Pdf = 'pdf';
    case Interactive = 'interactive';

    /**
     * @return list<self>
     */
    public static function casesOrdered(): array
    {
        return [self::Video, self::Text, self::Pdf, self::Interactive];
    }
}
