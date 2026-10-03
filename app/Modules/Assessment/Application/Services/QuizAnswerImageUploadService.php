<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Support\ImageUploadQuestionConfig;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class QuizAnswerImageUploadService
{
    /**
     * @return array{answer: QuizAttemptAnswer, media: Media, images: list<array<string, mixed>>}
     */
    public function upload(
        QuizAttempt $attempt,
        Question $question,
        UploadedFile $file,
        bool $replace = false,
    ): array {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new DomainException('ATTEMPT_EXPIRED: Attempt is not in progress.', 422);
        }

        if ($question->question_type !== QuestionType::ImageUpload) {
            throw ValidationException::withMessages([
                'image' => ['This question does not accept image uploads.'],
            ]);
        }

        $config = ImageUploadQuestionConfig::fromQuestion($question);
        $this->assertValidImage($file, $config);

        return DB::transaction(function () use ($attempt, $question, $file, $replace, $config): array {
            $answer = QuizAttemptAnswer::query()->firstOrCreate(
                [
                    'quiz_attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                ],
                [
                    'interactive_answer' => ['images' => []],
                ]
            );

            $existingCount = $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->count();
            if ($replace || $config->maxImages === 1) {
                $answer->clearMediaCollection(QuizAttemptAnswer::MEDIA_COLLECTION);
                $existingCount = 0;
            }

            if ($existingCount >= $config->maxImages) {
                $message = $config->maxImages === 1
                    ? 'يمكنك رفع صورة واحدة فقط.'
                    : "يمكنك رفع {$config->maxImages} صور كحد أقصى.";

                throw ValidationException::withMessages([
                    'image' => [$message],
                ]);
            }

            $safeName = Str::uuid()->toString().'.'.$this->extensionForMime((string) $file->getMimeType());

            $media = $answer
                ->addMedia($file)
                ->usingFileName($safeName)
                ->usingName($safeName)
                ->withCustomProperties([
                    'original_client_name' => $file->getClientOriginalName(),
                    'uploaded_at' => now()->toIso8601String(),
                ])
                ->toMediaCollection(QuizAttemptAnswer::MEDIA_COLLECTION, 'local');

            $this->syncInteractiveAnswerMeta($answer);

            return [
                'answer' => $answer->fresh(),
                'media' => $media,
                'images' => $this->publicImageList($answer->fresh(), $attempt),
            ];
        });
    }

    public function deleteImage(QuizAttempt $attempt, Question $question, int $mediaId): QuizAttemptAnswer
    {
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new DomainException('ATTEMPT_EXPIRED: Attempt is not in progress.', 422);
        }

        $answer = QuizAttemptAnswer::query()
            ->where('quiz_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        if ($answer === null) {
            throw new DomainException('QUESTION_NOT_FOUND: No uploaded image for this question.', 404);
        }

        $media = $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->firstWhere('id', $mediaId);
        if ($media === null) {
            throw new DomainException('QUESTION_NOT_FOUND: Image not found.', 404);
        }

        $media->delete();
        $this->syncInteractiveAnswerMeta($answer);

        return $answer->fresh() ?? $answer;
    }

    public function resolveMedia(QuizAttempt $attempt, Question $question, int $mediaId): Media
    {
        $answer = QuizAttemptAnswer::query()
            ->where('quiz_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        if ($answer === null) {
            throw new DomainException('QUESTION_NOT_FOUND: Image not found.', 404);
        }

        $media = $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->firstWhere('id', $mediaId);
        if ($media === null) {
            throw new DomainException('QUESTION_NOT_FOUND: Image not found.', 404);
        }

        return $media;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicImageList(QuizAttemptAnswer $answer, QuizAttempt $attempt): array
    {
        return $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->map(function (Media $media) use ($attempt, $answer): array {
            return [
                'id' => $media->id,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => url(sprintf(
                    '/api/v1/quiz-attempts/%d/questions/%d/images/%d',
                    $attempt->id,
                    $answer->question_id,
                    $media->id,
                )),
            ];
        })->values()->all();
    }

    public function imageCount(QuizAttemptAnswer $answer): int
    {
        return $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->count();
    }

    private function syncInteractiveAnswerMeta(QuizAttemptAnswer $answer): void
    {
        $answer->load('media');
        $images = $answer->getMedia(QuizAttemptAnswer::MEDIA_COLLECTION)->map(fn (Media $m): array => [
            'media_id' => $m->id,
            'mime_type' => $m->mime_type,
            'size' => $m->size,
        ])->values()->all();

        $answer->update([
            'interactive_answer' => [
                'type' => 'image_upload',
                'images' => $images,
                'count' => count($images),
            ],
        ]);
    }

    private function assertValidImage(UploadedFile $file, ImageUploadQuestionConfig $config): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'image' => ['يجب اختيار صورة.'],
            ]);
        }

        if ($file->getSize() !== null && $file->getSize() > $config->maxBytes()) {
            throw ValidationException::withMessages([
                'image' => ["حجم الصورة يجب ألا يتجاوز {$config->maxSizeMb} ميجابايت."],
            ]);
        }

        $mime = (string) ($file->getMimeType() ?: '');
        if (! in_array($mime, $config->allowedMimes, true)) {
            throw ValidationException::withMessages([
                'image' => ['نوع الصورة غير مدعوم.'],
            ]);
        }

        // Secondary check via getimagesize — rejects non-images / many polyglots.
        $path = $file->getRealPath();
        if ($path === false || @getimagesize($path) === false) {
            throw ValidationException::withMessages([
                'image' => ['نوع الصورة غير مدعوم.'],
            ]);
        }

        // Reject SVG explicitly even if somehow labeled as image/*
        $original = strtolower($file->getClientOriginalName());
        if (str_ends_with($original, '.svg') || str_ends_with($original, '.php') || str_ends_with($original, '.html')) {
            throw ValidationException::withMessages([
                'image' => ['نوع الصورة غير مدعوم.'],
            ]);
        }
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
