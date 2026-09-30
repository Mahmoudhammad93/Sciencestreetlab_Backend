<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Migration\Application\Services\WordPress\WordPressMediaImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaRollbackService;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaTransferPlanner;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use App\Modules\Migration\Infrastructure\Persistence\Models\LegacyMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WordPressMediaM2ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_command_defaults_to_dry_run_without_persist(): void
    {
        config(['wordpress.real_persist' => null]);
        $run = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'notes' => ['note' => 'm2-test'],
            'started_at' => now(),
        ]);

        // Avoid loading staging manifests in unit isolation — command still requires run id.
        $this->artisan('migration:wordpress:media', [
            '--migration-run' => $run->id,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_planner_skips_product_6912_identity_in_constants(): void
    {
        $this->assertSame(6912, WordPressMediaImporter::SKIPPED_LEGACY_PRODUCT_ID);
        $plan = app(WordPressMediaTransferPlanner::class)->productFeaturedPlan(1);
        $this->assertSame('PRODUCT_IMAGE_ATTACH', $plan['future_import_action']);
    }

    public function test_rollback_dry_run_never_deletes_business_entities(): void
    {
        $run = LegacyMigrationRun::query()->create([
            'source' => 'wordpress',
            'environment' => 'testing',
            'status' => 'running',
            'notes' => ['note' => 'm2-rollback'],
            'started_at' => now(),
        ]);

        $plan = app(WordPressMediaRollbackService::class)->planOrExecute($run->id, true);
        $this->assertSame('dry_run', $plan['status']);
        $this->assertSame(0, $plan['would_delete_products']);
        $this->assertSame(0, $plan['would_delete_courses']);
        $this->assertSame(0, $plan['would_delete_lessons']);
        $this->assertSame(0, $plan['would_delete_topics']);
    }

    public function test_real_persist_gate_blocks_media_without_flag(): void
    {
        config(['wordpress.real_persist' => null]);
        $gate = app(WordPressRealPersistGate::class);
        $auth = $gate->authorizeMutation(1, 'media');
        $this->assertFalse($auth['ok']);
    }
}
