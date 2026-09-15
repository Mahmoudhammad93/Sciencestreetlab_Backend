<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;

final class ProductPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Product $product): array
    {
        $product->loadMissing([
            'category.media',
            'curriculumAlignments',
            'relatedCourse',
            'media',
        ]);

        $locale = app()->getLocale();
        $payload = $product->toArray();

        $payload['difficulty_level'] = $product->difficulty_level;
        $payload['target_age'] = $product->target_age;
        $payload['key_benefits'] = $product->key_benefits ?? [];
        $payload['scientific_concepts'] = $product->scientific_concepts ?? [];
        $payload['design_lab_description'] = $product->design_lab_description;
        $payload['creative_lab_description'] = $product->creative_lab_description;
        $payload['related_course_id'] = $product->related_course_id;
        $payload['gallery'] = $product->gallery_urls;
        $payload['concept_images'] = $product->concept_image_urls;
        $payload['curriculum_alignment'] = $product->curriculumAlignments
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($row) => [
                'grade_level' => $row->grade_level,
                'lesson_name' => $row->lesson_name,
            ])
            ->all();
        $payload['related_course'] = $this->presentRelatedCourse($product, $locale);

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
    private function presentRelatedCourse(Product $product, string $locale): ?array
    {
        $course = $product->relatedCourse;

        if ($course === null) {
            return null;
        }

        $frontend = rtrim((string) config('sciencestreet.frontend_url', ''), '/');

        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->getTranslation('title', $locale),
            'tagline' => $course->getTranslation('short_description', $locale) ?: null,
            'description' => $course->getTranslation('description', $locale) ?: null,
            'design_lab_description' => $product->design_lab_description,
            'creative_lab_description' => $product->creative_lab_description,
            'designLabDescription' => $product->design_lab_description,
            'creativeLabDescription' => $product->creative_lab_description,
            'course_url' => $frontend !== '' ? $frontend.'/courses/'.$course->slug : '/courses/'.$course->slug,
            'courseUrl' => $frontend !== '' ? $frontend.'/courses/'.$course->slug : '/courses/'.$course->slug,
        ];
    }
}
