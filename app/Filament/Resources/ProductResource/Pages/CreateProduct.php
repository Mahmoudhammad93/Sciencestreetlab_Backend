<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Modules\Catalog\Application\Services\ProductEducationalSpecSyncService;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** @var list<array{grade_level?: string, lesson_name?: string}>|null */
    private ?array $pendingAlignments = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['currency'] ??= 'EGP';
        $data['sort_order'] ??= 0;

        if (($data['status'] ?? null) === ProductStatus::Published->value && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        $this->pendingAlignments = array_values($data['curriculum_alignments'] ?? []);
        unset($data['curriculum_alignments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->pendingAlignments !== null) {
            app(ProductEducationalSpecSyncService::class)
                ->syncCurriculumAlignments($this->record, $this->pendingAlignments);
            $this->pendingAlignments = null;
        }
    }
}
