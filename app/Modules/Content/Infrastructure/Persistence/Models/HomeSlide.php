<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Persistence\Models;

use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HomeSlide extends Model
{
    protected $fillable = [
        'image',
        'background_color',
        'link',
        'link_target',
        'is_active',
        'sort_order',
        'display_duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'display_duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (HomeSlide $slide): void {
            if ($slide->isDirty('image')) {
                $previous = $slide->getOriginal('image');
                if (is_string($previous) && $previous !== '') {
                    self::deleteOwnedImage($previous);
                }
            }
        });

        static::deleting(function (HomeSlide $slide): void {
            self::deleteOwnedImage($slide->image);
        });
    }

    /**
     * @param  Builder<HomeSlide>  $query
     * @return Builder<HomeSlide>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<HomeSlide>  $query
     * @return Builder<HomeSlide>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function publicImageUrl(): ?string
    {
        return PublicMediaUrl::make($this->image);
    }

    public static function isSafeLink(?string $link): bool
    {
        if ($link === null || trim($link) === '') {
            return true;
        }

        $value = trim($link);

        if (preg_match('#^(javascript|data|vbscript):#i', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        return preg_match('#^https?://#i', $value) === 1;
    }

    public static function isValidBackgroundColor(string $color): bool
    {
        return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color) === 1;
    }

    private static function deleteOwnedImage(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $normalized = PublicMediaUrl::toDiskPath($path) ?? ltrim($path, '/');

        if (! str_starts_with($normalized, 'home-slides/')) {
            return;
        }

        Storage::disk('public')->delete($normalized);
    }
};
