<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

final class BotDetector
{
    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }

        $ua = strtolower($userAgent);
        /** @var list<string> $needles */
        $needles = config('social_attribution.bot_user_agent_substrings', []);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($ua, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
