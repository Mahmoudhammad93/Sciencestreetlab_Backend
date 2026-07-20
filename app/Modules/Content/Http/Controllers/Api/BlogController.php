<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Content\Infrastructure\Persistence\Models\Post;
use Illuminate\Http\JsonResponse;

final class BlogController extends Controller
{
    public function index(): JsonResponse
    {
        $posts = Post::query()
            ->where('is_published', true)
            ->with('author:id,name')
            ->latest('published_at')
            ->paginate(12);

        return response()->json([
            'data' => collect($posts->items())->map(fn (Post $post) => $this->summary($post)),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $post = Post::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->with('author:id,name')
            ->firstOrFail();

        return response()->json([
            'data' => array_merge($this->summary($post), [
                'content' => $post->getTranslations('content'),
                'meta' => $post->meta,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Post $post): array
    {
        return [
            'slug' => $post->slug,
            'title' => $post->getTranslations('title'),
            'excerpt' => $post->getTranslations('excerpt'),
            'author' => $post->author?->name,
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
