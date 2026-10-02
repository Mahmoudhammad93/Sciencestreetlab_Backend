<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Support;

use App\Modules\Learning\Infrastructure\Persistence\Models\Course;

/**
 * Resolve frontend/canonical course slugs to the live Course row.
 *
 * Production may store an Arabic WordPress slug while the app and competition
 * CTAs still link to the English shell slug (microscope-course).
 */
final class CourseSlugResolver
{
    public function resolve(string $slug, bool $publishedOnly = true): ?Course
    {
        $query = Course::query()->where('slug', $slug);
        if ($publishedOnly) {
            $query->where('is_published', true);
        }

        $course = $query->first();
        if ($course !== null) {
            return $course;
        }

        foreach (config('course.slug_alias_groups', []) as $group) {
            if (! is_array($group) || ! in_array($slug, $group, true)) {
                continue;
            }

            $aliases = array_values(array_filter(
                $group,
                static fn ($value): bool => is_string($value) && $value !== ''
            ));
            if ($aliases === []) {
                continue;
            }

            $aliasQuery = Course::query()->whereIn('slug', $aliases)->orderBy('id');
            if ($publishedOnly) {
                $aliasQuery->where('is_published', true);
            }

            $resolved = $aliasQuery->first();
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
