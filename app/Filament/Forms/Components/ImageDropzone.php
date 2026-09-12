<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Support\PublicMediaUrl;
use Filament\Forms\Components\FileUpload;

/**
 * Shared drag-and-drop image upload with preview (same UX as Product form).
 */
final class ImageDropzone
{
    public static function make(
        string $name,
        string $directory,
        string $label = 'Image',
        ?string $helperText = null,
    ): FileUpload {
        return FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk('public')
            ->directory($directory)
            ->visibility('public')
            ->maxFiles(1)
            ->panelLayout('integrated')
            ->imagePreviewHeight('220')
            ->imageEditor()
            ->imageEditorAspectRatios([
                null,
                '16:9',
                '4:3',
                '1:1',
            ])
            ->maxSize(5120)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->helperText($helperText ?? 'Drag and drop an image here, or click to browse.')
            ->downloadable()
            ->openable()
            ->columnSpanFull()
            ->afterStateHydrated(function (FileUpload $component, mixed $state): void {
                $path = PublicMediaUrl::toDiskPath($state);
                if ($path !== null) {
                    $component->state($path);
                }
            })
            ->dehydrateStateUsing(fn (mixed $state): ?string => PublicMediaUrl::normalizeStoredValue($state));
    }

    public static function publicUrl(?string $value): ?string
    {
        return PublicMediaUrl::make($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function preserveIfEmpty(array $data, string $field, ?string $existing): array
    {
        if (! array_key_exists($field, $data)) {
            return $data;
        }

        if (blank($data[$field]) && filled($existing)) {
            unset($data[$field]);
        }

        return $data;
    }
}
