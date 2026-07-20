<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Content\Infrastructure\Persistence\Models\Page;
use App\Modules\Content\Infrastructure\Persistence\Models\Post;
use Illuminate\Http\JsonResponse;

final class PageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'slug' => $page->slug,
                'template' => $page->template,
                'title' => $page->getTranslations('title'),
                'content' => $page->getTranslations('content'),
                'meta' => $page->meta,
                'published_at' => $page->published_at?->toIso8601String(),
            ],
        ]);
    }
}
