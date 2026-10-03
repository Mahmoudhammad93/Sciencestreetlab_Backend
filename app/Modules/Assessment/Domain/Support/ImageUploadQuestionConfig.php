<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Support;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;

/**
 * Reads/writes image_upload settings from Question.interactive_config (no DDL).
 *
 * Shape:
 * interactive_config.upload = {
 *   max_images: int,
 *   max_size_mb: int|float,
 *   allowed_mimes: list<string>,
 *   required: bool
 * }
 */
final class ImageUploadQuestionConfig
{
    public const DEFAULT_MAX_IMAGES = 1;

    public const DEFAULT_MAX_SIZE_MB = 5;

    /** @var list<string> */
    public const DEFAULT_ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        public readonly int $maxImages,
        public readonly float $maxSizeMb,
        /** @var list<string> */
        public readonly array $allowedMimes,
        public readonly bool $required,
    ) {}

    public static function fromQuestion(Question $question): self
    {
        $config = is_array($question->interactive_config) ? $question->interactive_config : [];
        $upload = is_array($config['upload'] ?? null) ? $config['upload'] : [];

        $maxImages = (int) ($upload['max_images'] ?? self::DEFAULT_MAX_IMAGES);
        if ($maxImages < 1) {
            $maxImages = self::DEFAULT_MAX_IMAGES;
        }

        $maxSizeMb = (float) ($upload['max_size_mb'] ?? self::DEFAULT_MAX_SIZE_MB);
        if ($maxSizeMb <= 0) {
            $maxSizeMb = self::DEFAULT_MAX_SIZE_MB;
        }

        $mimes = $upload['allowed_mimes'] ?? self::DEFAULT_ALLOWED_MIMES;
        if (! is_array($mimes) || $mimes === []) {
            $mimes = self::DEFAULT_ALLOWED_MIMES;
        }
        $mimes = array_values(array_unique(array_map('strval', $mimes)));

        $required = array_key_exists('required', $upload)
            ? (bool) $upload['required']
            : true;

        return new self($maxImages, $maxSizeMb, $mimes, $required);
    }

    /**
     * Merge admin form fields into interactive_config without wiping unrelated keys.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public static function mergeIntoInteractiveConfig(
        array $existing,
        int $maxImages,
        float $maxSizeMb,
        bool $required = true,
    ): array {
        $upload = is_array($existing['upload'] ?? null) ? $existing['upload'] : [];
        $upload['max_images'] = max(1, $maxImages);
        $upload['max_size_mb'] = $maxSizeMb > 0 ? $maxSizeMb : self::DEFAULT_MAX_SIZE_MB;
        $upload['allowed_mimes'] = self::DEFAULT_ALLOWED_MIMES;
        $upload['required'] = $required;
        $existing['upload'] = $upload;

        return $existing;
    }

    public function maxBytes(): int
    {
        return (int) round($this->maxSizeMb * 1024 * 1024);
    }

    /**
     * Student-facing payload (no secrets).
     *
     * @return array{max_images: int, max_size_mb: float, allowed_types: list<string>, required: bool}
     */
    public function toStudentArray(): array
    {
        return [
            'max_images' => $this->maxImages,
            'max_size_mb' => $this->maxSizeMb,
            'allowed_types' => $this->allowedMimes,
            'required' => $this->required,
        ];
    }
}
