<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;

final class ProductPresenter
{
    /**
     * @param  array{with_reviews?: bool}  $options
     * @return array<string, mixed>
     */
    public function present(Product $product, array $options = []): array
    {
        $withReviews = $options['with_reviews'] ?? true;

        $product->loadMissing([
            'category.media',
            'curriculumAlignments',
            'relatedCourse',
            'media',
        ]);

        if ($withReviews) {
            $product->loadMissing(['approvedReviews.user']);
        }

        $locale = app()->getLocale();
        $fallback = config('sciencestreet.default_locale', 'ar');
        $payload = $product->toArray();

        $payload['difficulty_level'] = $product->difficulty_level;
        $payload['target_age'] = $product->target_age;
        $payload['key_benefits'] = $product->key_benefits ?? [];
        $payload['scientific_concepts'] = $this->localizedList($product, 'scientific_concepts', $locale, $fallback);
        $payload['design_lab_description'] = $this->localizedString($product, 'design_lab_description', $locale, $fallback);
        $payload['creative_lab_description'] = $this->localizedString($product, 'creative_lab_description', $locale, $fallback);
        $payload['related_course_id'] = $product->related_course_id;
        $payload['gallery'] = $product->gallery_urls;
        $payload['concept_images'] = $product->concept_image_urls;
        $payload['curriculum_alignment'] = $product->curriculumAlignments
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($row) => [
                'grade_level' => $this->localizedString($row, 'grade_level', $locale, $fallback),
                'lesson_name' => $this->localizedString($row, 'lesson_name', $locale, $fallback),
            ])
            ->all();
        $payload['related_course'] = $this->presentRelatedCourse($product, $locale, $fallback);

        $payload['reviews_summary'] = [
            'average_rating' => round((float) $product->average_rating, 2),
            'reviews_count' => (int) $product->review_count,
        ];
        $payload['reviewsSummary'] = $payload['reviews_summary'];

        if ($withReviews) {
            $payload['reviews'] = $product->approvedReviews
                ->sortByDesc('created_at')
                ->values()
                ->map(fn ($review) => [
                    'id' => $review->id,
                    'user' => [
                        'name' => $review->user?->name ?? 'Customer',
                    ],
                    'rating' => $review->rating,
                    'review' => $review->review,
                    'created_at' => optional($review->created_at)?->toIso8601String(),
                ])
                ->all();
        }

        // Frontend-friendly camelCase aliases (non-breaking additions).
        $payload['difficultyLevel'] = $payload['difficulty_level'];
        $payload['targetAge'] = $payload['target_age'];
        $payload['keyBenefits'] = $payload['key_benefits'];
        $payload['scientificConcepts'] = $payload['scientific_concepts'];
        $payload['conceptImages'] = $payload['concept_images'];
        $payload['curriculumAlignment'] = $payload['curriculum_alignment'];
        $payload['relatedCourse'] = $payload['related_course'];
        $payload['designLabDescription'] = $payload['design_lab_description'];
        $payload['creativeLabDescription'] = $payload['creative_lab_description'];

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentRelatedCourse(Product $product, string $locale, string $fallback): ?array
    {
        $course = $product->relatedCourse;

        if ($course === null) {
            return null;
        }

        $frontend = rtrim((string) config('sciencestreet.frontend_url', ''), '/');
        $design = $this->localizedString($product, 'design_lab_description', $locale, $fallback);
        $creative = $this->localizedString($product, 'creative_lab_description', $locale, $fallback);

        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->getTranslation('title', $locale) ?: $course->getTranslation('title', $fallback),
            'tagline' => $course->getTranslation('short_description', $locale)
                ?: $course->getTranslation('short_description', $fallback)
                ?: null,
            'description' => $course->getTranslation('description', $locale)
                ?: $course->getTranslation('description', $fallback)
                ?: null,
            'design_lab_description' => $design,
            'creative_lab_description' => $creative,
            'designLabDescription' => $design,
            'creativeLabDescription' => $creative,
            'course_url' => $frontend !== '' ? $frontend.'/courses/'.$course->slug : '/courses/'.$course->slug,
            'courseUrl' => $frontend !== '' ? $frontend.'/courses/'.$course->slug : '/courses/'.$course->slug,
        ];
    }

    /**
     * @param  object{getTranslation: callable}  $model
     */
    private function localizedString(object $model, string $field, string $locale, string $fallback): ?string
    {
        $value = $model->getTranslation($field, $locale, false);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallbackValue = $model->getTranslation($field, $fallback, false);
        if (is_string($fallbackValue) && $fallbackValue !== '') {
            return $fallbackValue;
        }

        // Last resort: any available translation.
        if (method_exists($model, 'getTranslations')) {
            foreach ($model->getTranslations($field) as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  object{getTranslation: callable}  $model
     * @return list<string>
     */
    private function localizedList(object $model, string $field, string $locale, string $fallback): array
    {
        $value = $model->getTranslation($field, $locale, false);
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => is_string($item) && $item !== ''));
        }

        $fallbackValue = $model->getTranslation($field, $fallback, false);
        if (is_array($fallbackValue)) {
            return array_values(array_filter($fallbackValue, fn ($item) => is_string($item) && $item !== ''));
        }

        if (method_exists($model, 'getTranslations')) {
            foreach ($model->getTranslations($field) as $candidate) {
                if (is_array($candidate)) {
                    return array_values(array_filter($candidate, fn ($item) => is_string($item) && $item !== ''));
                }
            }
        }

        return [];
    }
}
