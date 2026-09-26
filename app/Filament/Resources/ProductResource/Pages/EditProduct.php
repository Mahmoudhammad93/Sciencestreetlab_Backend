<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Modules\Catalog\Application\Services\ProductEducationalSpecSyncService;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\SocialCommerce\Application\Events\ProductSalesChannelSyncRequested;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** @var list<array{grade_level?: array{en?: string, ar?: string}|string, lesson_name?: array{en?: string, ar?: string}|string}>|null */
    private ?array $pendingAlignments = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['curriculum_alignments'] = $this->record->curriculumAlignments()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => [
                'grade_level' => $row->getTranslations('grade_level'),
                'lesson_name' => $row->getTranslations('lesson_name'),
                'sort_order' => $row->sort_order,
            ])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? null) === ProductStatus::Published->value && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        $this->pendingAlignments = array_values($data['curriculum_alignments'] ?? []);
        unset($data['curriculum_alignments']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->pendingAlignments !== null) {
            app(ProductEducationalSpecSyncService::class)
                ->syncCurriculumAlignments($this->record, $this->pendingAlignments);
            $this->pendingAlignments = null;
        }

        ProductSalesChannelSyncRequested::dispatch($this->record);
    }
}
