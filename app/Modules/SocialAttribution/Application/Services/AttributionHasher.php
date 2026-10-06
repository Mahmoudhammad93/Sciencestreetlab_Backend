<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

final class AttributionHasher
{
    public function hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $pepper = (string) config('social_attribution.hash_pepper', '');

        return hash('sha256', $pepper.'|'.$value);
    }
}
