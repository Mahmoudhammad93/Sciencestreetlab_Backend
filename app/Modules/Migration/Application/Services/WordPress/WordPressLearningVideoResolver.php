<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Evidence-based video resolution for imported learning entities.
 * Never invents mappings; ambiguous entities keep null canonical URLs.
 */
final class WordPressLearningVideoResolver
{
    public const NO_VIDEO = 'NO_VIDEO';

    public const ONE_CLEAR_VIDEO = 'ONE_CLEAR_VIDEO';

    public const MULTIPLE_REFERENCES_SAME_VIDEO = 'MULTIPLE_REFERENCES_SAME_VIDEO';

    public const MULTIPLE_DISTINCT_VIDEOS = 'MULTIPLE_DISTINCT_VIDEOS';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const COURSE_VIDEO_TARGET_GAP = 'COURSE_VIDEO_TARGET_GAP';

    /** Provider for YouTube references (frontend VideoProvider includes youtube). */
    public const PROVIDER_YOUTUBE = 'youtube';

    public function __construct(
        private readonly YouTubeVideoReferenceNormalizer $normalizer = new YouTubeVideoReferenceNormalizer,
    ) {}

    /**
     * @param  list<array{source: string, url: string}>  $references
     * @return array{
     *   resolution_status: string,
     *   canonical_video_url: ?string,
     *   provider: ?string,
     *   normalized_unique_video_ids: list<string>,
     *   source_evidence: ?string,
     *   source_reference_count: int,
     *   notes: ?string
     * }
     */
    public function resolve(string $entityType, array $references): array
    {
        if ($entityType === 'course') {
            $ids = $this->normalizer->uniqueVideoIds(array_column($references, 'url'));

            return [
                'resolution_status' => self::COURSE_VIDEO_TARGET_GAP,
                'canonical_video_url' => null,
                'provider' => null,
                'normalized_unique_video_ids' => $ids,
                'source_evidence' => $references[0]['source'] ?? null,
                'source_reference_count' => count($references),
                'notes' => 'Laravel Course has no video_url/provider target; do not assign course videos to lessons.',
            ];
        }

        if ($references === []) {
            return $this->result(self::NO_VIDEO, null, null, [], null, 0, null);
        }

        $buckets = [
            'structured' => [],
            'embed' => [],
            'post_content' => [],
            'oembed' => [],
        ];

        foreach ($references as $ref) {
            $source = (string) ($ref['source'] ?? '');
            $bucket = $this->bucketForSource($source);
            if ($bucket !== null) {
                $buckets[$bucket][] = $ref;
            }
        }

        $chosenName = null;
        $chosenRefs = [];
        foreach (['structured', 'embed', 'post_content', 'oembed'] as $name) {
            $ids = $this->normalizer->uniqueVideoIds(array_column($buckets[$name], 'url'));
            if ($ids !== []) {
                $chosenName = $name === 'oembed' ? 'oembed_fallback' : $name;
                $chosenRefs = $buckets[$name];
                break;
            }
        }

        $uniqueIds = $this->normalizer->uniqueVideoIds(array_column($chosenRefs, 'url'));
        $count = count($references);

        if ($uniqueIds === []) {
            return $this->result(
                self::AMBIGUOUS,
                null,
                null,
                [],
                $chosenName,
                $count,
                'references present but no extractable YouTube video id'
            );
        }

        if (count($uniqueIds) > 1) {
            return $this->result(
                self::MULTIPLE_DISTINCT_VIDEOS,
                null,
                null,
                $uniqueIds,
                $chosenName,
                $count,
                'multiple distinct youtube ids from '.$chosenName.': '.implode(',', $uniqueIds)
            );
        }

        $status = $count > 1 ? self::MULTIPLE_REFERENCES_SAME_VIDEO : self::ONE_CLEAR_VIDEO;
        $notes = $chosenName === 'oembed_fallback'
            ? 'canonical from oembed fallback; structured/content empty or non-extractable'
            : null;

        return $this->result(
            $status,
            'https://www.youtube.com/watch?v='.$uniqueIds[0],
            self::PROVIDER_YOUTUBE,
            $uniqueIds,
            $chosenName,
            $count,
            $notes
        );
    }

    private function bucketForSource(string $source): ?string
    {
        if (str_contains($source, '_oembed_')) {
            return 'oembed';
        }
        if (str_starts_with($source, 'postmeta:_sfwd-') || str_contains($source, 'lesson_video_url')) {
            return 'structured';
        }
        if (str_contains($source, 'video_embed') || str_contains($source, 'embed_code')) {
            return 'embed';
        }
        if ($source === 'post_content') {
            return 'post_content';
        }

        return null;
    }

    /**
     * @param  list<string>  $ids
     * @return array{
     *   resolution_status: string,
     *   canonical_video_url: ?string,
     *   provider: ?string,
     *   normalized_unique_video_ids: list<string>,
     *   source_evidence: ?string,
     *   source_reference_count: int,
     *   notes: ?string
     * }
     */
    private function result(
        string $status,
        ?string $canonical,
        ?string $provider,
        array $ids,
        ?string $evidence,
        int $count,
        ?string $notes,
    ): array {
        return [
            'resolution_status' => $status,
            'canonical_video_url' => $canonical,
            'provider' => $provider,
            'normalized_unique_video_ids' => $ids,
            'source_evidence' => $evidence,
            'source_reference_count' => $count,
            'notes' => $notes,
        ];
    }
}
