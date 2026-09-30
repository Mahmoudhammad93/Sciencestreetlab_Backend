<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Deterministic M2 transfer plans — does not download or attach media.
 */
final class WordPressMediaTransferPlanner
{
    public const PRODUCT_IMAGE_COLLECTION = 'image';

    public const PRODUCT_GALLERY_COLLECTION = 'gallery';

    public const COURSE_STORAGE_DIRECTORY = 'courses';

    public const INLINE_STORAGE_PREFIX = 'migration/wordpress-inline';

    /**
     * Featured image → Spatie collection "image" (singleFile).
     *
     * @return array{planned_target: string, planned_collection_or_field: string, planned_order: int, future_import_action: string}
     */
    public function productFeaturedPlan(int $legacyAttachmentId): array
    {
        return [
            'planned_target' => 'product',
            'planned_collection_or_field' => self::PRODUCT_IMAGE_COLLECTION,
            'planned_order' => 0,
            'future_import_action' => 'PRODUCT_IMAGE_ATTACH',
            'legacy_attachment_id' => $legacyAttachmentId,
        ];
    }

    /**
     * Gallery → Spatie collection "gallery"; order preserves WordPress CSV order.
     *
     * @param  list<int>  $galleryAttachmentIdsInOrder
     * @return list<array{planned_target: string, planned_collection_or_field: string, planned_order: int, future_import_action: string, legacy_attachment_id: int}>
     */
    public function productGalleryPlans(array $galleryAttachmentIdsInOrder): array
    {
        $plans = [];
        foreach (array_values($galleryAttachmentIdsInOrder) as $order => $attachmentId) {
            $plans[] = [
                'planned_target' => 'product',
                'planned_collection_or_field' => self::PRODUCT_GALLERY_COLLECTION,
                'planned_order' => $order,
                'future_import_action' => 'PRODUCT_GALLERY_ATTACH',
                'legacy_attachment_id' => (int) $attachmentId,
            ];
        }

        return $plans;
    }

    /**
     * Course featured → public disk courses/ then courses.image_url relative path.
     *
     * @return array{planned_target: string, planned_collection_or_field: string, planned_order: int, future_import_action: string, storage_directory: string, planned_relative_path: string}
     */
    public function courseImagePlan(int $legacyAttachmentId, string $relativePath): array
    {
        $basename = basename(str_replace('\\', '/', $relativePath));

        return [
            'planned_target' => 'course',
            'planned_collection_or_field' => 'image_url',
            'planned_order' => 0,
            'future_import_action' => 'COURSE_IMAGE_STORE',
            'storage_directory' => self::COURSE_STORAGE_DIRECTORY,
            'planned_relative_path' => self::COURSE_STORAGE_DIRECTORY.'/'.$legacyAttachmentId.'_'.$basename,
            'legacy_attachment_id' => $legacyAttachmentId,
        ];
    }

    /**
     * Inline learning image → public disk path for future HTML rewrite.
     *
     * @return array{planned_target: string, planned_collection_or_field: string, planned_order: int, future_import_action: string, planned_relative_path: string}
     */
    public function inlineImagePlan(int $legacyAttachmentId, string $relativePath): array
    {
        $basename = basename(str_replace('\\', '/', $relativePath));

        return [
            'planned_target' => 'learning_content',
            'planned_collection_or_field' => 'content_html_src',
            'planned_order' => 0,
            'future_import_action' => 'INLINE_IMAGE_STORE',
            'planned_relative_path' => self::INLINE_STORAGE_PREFIX.'/'.$legacyAttachmentId.'/'.$basename,
            'legacy_attachment_id' => $legacyAttachmentId,
        ];
    }

    /**
     * Spatie Media custom properties for future idempotent attach.
     *
     * @return array<string, scalar|null>
     */
    public function idempotencyCustomProperties(
        int $legacyAttachmentId,
        string $relativePath,
        string $canonicalSourceUrl,
        ?int $migrationRunId = null,
    ): array {
        return [
            'legacy_source' => 'wordpress',
            'legacy_attachment_id' => $legacyAttachmentId,
            'legacy_relative_path' => $relativePath,
            'legacy_source_url' => $canonicalSourceUrl,
            'migration_run_id' => $migrationRunId,
        ];
    }

    /**
     * Whether an existing Spatie media item already represents this attachment.
     *
     * @param  iterable<int, object>  $existingMedia  objects with getCustomProperty(string): mixed
     */
    public function alreadyAttached(iterable $existingMedia, int $legacyAttachmentId): bool
    {
        foreach ($existingMedia as $media) {
            if (! is_object($media) || ! method_exists($media, 'getCustomProperty')) {
                continue;
            }
            if ((int) $media->getCustomProperty('legacy_attachment_id') === $legacyAttachmentId
                && (string) $media->getCustomProperty('legacy_source') === 'wordpress') {
                return true;
            }
        }

        return false;
    }
}
