<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Resources;

use App\Modules\Content\Application\Services\HomeSlideImageVariantService;
use App\Modules\Content\Infrastructure\Persistence\Models\HomeSlide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HomeSlide
 */
final class HomeSlideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HomeSlide $slide */
        $slide = $this->resource;

        $variants = app(HomeSlideImageVariantService::class)->describe($slide->image);

        return [
            'id' => $slide->id,
            'image_url' => $variants['image_url'] ?? $slide->publicImageUrl(),
            'image_srcset' => $variants['image_srcset'],
            'image_sizes' => $variants['image_sizes'],
            'image_width' => $variants['image_width'],
            'image_height' => $variants['image_height'],
            'background_color' => $slide->background_color,
            'link' => $slide->link,
            'link_target' => $slide->link_target,
            'sort_order' => $slide->sort_order,
            'duration_seconds' => max(1, (int) $slide->display_duration_seconds),
        ];
    }
}
