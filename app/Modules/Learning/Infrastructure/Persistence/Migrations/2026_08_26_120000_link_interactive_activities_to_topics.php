<?php

declare(strict_types=1);

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactive_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('interactive_activities', 'topic_id')) {
                $table->foreignId('topic_id')->nullable()->after('lesson_id')
                    ->constrained('topics')->nullOnDelete();
            }
            if (! Schema::hasColumn('interactive_activities', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('status');
            }
            if (! Schema::hasColumn('interactive_activities', 'max_attempts')) {
                $table->unsignedInteger('max_attempts')->nullable()->after('is_required');
            }
        });

        InteractiveActivity::query()->whereNull('topic_id')->orderBy('id')->each(function (InteractiveActivity $activity): void {
            $slug = 'interactive-'.$activity->id.'-'.Str::slug((string) ($activity->getTranslation('title', 'en') ?: $activity->uuid));
            $slug = Str::limit($slug, 80, '');

            $topic = Topic::query()->firstOrCreate(
                [
                    'lesson_id' => $activity->lesson_id,
                    'slug' => $slug,
                ],
                [
                    'sort_order' => 900 + (int) $activity->id,
                    'content_type' => 'interactive',
                    'is_published' => $activity->status?->value === 'published',
                    'title' => $activity->getTranslations('title') ?: ['en' => 'Interactive activity', 'ar' => 'نشاط تفاعلي'],
                    'content' => $activity->getTranslations('description') ?: null,
                ]
            );

            $activity->update(['topic_id' => $topic->id]);
        });
    }

    public function down(): void
    {
        Schema::table('interactive_activities', function (Blueprint $table): void {
            if (Schema::hasColumn('interactive_activities', 'topic_id')) {
                $table->dropConstrainedForeignId('topic_id');
            }
            if (Schema::hasColumn('interactive_activities', 'is_required')) {
                $table->dropColumn('is_required');
            }
            if (Schema::hasColumn('interactive_activities', 'max_attempts')) {
                $table->dropColumn('max_attempts');
            }
        });
    }
};
