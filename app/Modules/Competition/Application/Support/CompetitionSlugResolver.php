<?php

declare(strict_types=1);

namespace App\Modules\Competition\Application\Support;

use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class CompetitionSlugResolver
{
    public function resolve(string $slug): Competition
    {
        $competition = Competition::query()->where('slug', $slug)->first();
        if ($competition !== null) {
            return $competition;
        }

        foreach (config('competition.slug_alias_groups', []) as $group) {
            if (! is_array($group) || ! in_array($slug, $group, true)) {
                continue;
            }

            $aliases = array_values(array_filter($group, static fn ($value): bool => is_string($value) && $value !== ''));
            if ($aliases === []) {
                continue;
            }

            $resolved = Competition::query()
                ->whereIn('slug', $aliases)
                ->orderBy('id')
                ->first();

            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw (new ModelNotFoundException)->setModel(Competition::class, [$slug]);
    }
}
