<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BunnyStreamService;
use Database\Seeders\ProductionFiveStationsCoursesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Wipe database (keep only admin), upload Bunny video, seed 5 production courses.
 *
 * Example:
 * php artisan production:reset-and-seed-courses \
 *   /var/www/assets/lesson.mp4 \
 *   /var/www/interactive-examples
 */
final class ProductionResetAndSeedCoursesCommand extends Command
{
    protected $signature = 'production:reset-and-seed-courses
                            {video : Absolute path to the lesson MP4}
                            {examples : Absolute path to interactive examples directory}
                            {--admin-email=admin@sciencestreetlab.com : Admin email to preserve}
                            {--admin-password= : Optional password reset for admin (default keep hash)}
                            {--bunny-guid= : Reuse existing Bunny video guid (skip upload)}
                            {--force : Required confirmation flag}';

    protected $description = 'Fresh migrate, keep admin, upload Bunny video, seed 5 courses';

    public function handle(BunnyStreamService $bunny): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force (this wipes the database).');

            return self::FAILURE;
        }

        $videoPath = (string) $this->argument('video');
        $examplesDir = (string) $this->argument('examples');
        $adminEmail = (string) $this->option('admin-email');

        if (! is_file($videoPath) && ! filled($this->option('bunny-guid'))) {
            $this->error("Video file not found: {$videoPath}");

            return self::FAILURE;
        }

        if (! is_dir($examplesDir)) {
            $this->error("Examples directory not found: {$examplesDir}");

            return self::FAILURE;
        }

        $adminSnapshot = $this->captureAdmin($adminEmail);
        if ($adminSnapshot === null) {
            $this->warn("Admin {$adminEmail} not found before wipe — will recreate with password 'password' unless --admin-password is set.");
        } else {
            $this->info("Captured admin: {$adminSnapshot['email']}");
        }

        $this->warn('Running migrate:fresh --force ...');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->line(Artisan::output());

        $this->info('Seeding roles/permissions...');
        $this->call(RolePermissionSeeder::class);

        $admin = $this->restoreAdmin($adminSnapshot, $adminEmail, $this->option('admin-password'));
        $this->info("Admin ready: {$admin->email} (id {$admin->id})");

        if (filled($this->option('bunny-guid'))) {
            $guid = (string) $this->option('bunny-guid');
            $playback = $bunny->playbackUrl($guid);
            $this->info("Reusing Bunny video {$guid}");
        } else {
            if (! $bunny->isConfigured()) {
                $this->error('Bunny Stream is not configured on this server.');

                return self::FAILURE;
            }

            $this->info('Uploading video to Bunny Stream (may take a few minutes)...');
            $uploaded = $bunny->createAndUpload('Science Street — Production Lesson Video', $videoPath);
            $guid = $uploaded['guid'];
            $playback = $uploaded['playback_url'];
            $this->info("Bunny video: {$guid}");
            $this->line("Playback: {$playback}");
        }

        Config::set('production.examples_dir', $examplesDir);
        Config::set('production.bunny_video_id', $guid);
        Config::set('production.bunny_playback_url', $playback);

        $this->info('Seeding 5 courses...');
        $this->call(ProductionFiveStationsCoursesSeeder::class);

        $users = User::query()->count();
        $courses = DB::table('courses')->count();
        $topics = DB::table('topics')->count();

        $this->newLine();
        $this->info("Done. users={$users} courses={$courses} topics={$topics}");
        $this->line('Admin: '.$admin->email);

        return self::SUCCESS;
    }

    /**
     * @return array{email: string, name: string, password: string, locale: string|null}|null
     */
    private function captureAdmin(string $email): ?array
    {
        $admin = User::query()->where('email', $email)->first();
        if (! $admin) {
            return null;
        }

        return [
            'email' => (string) $admin->email,
            'name' => (string) $admin->name,
            'password' => (string) $admin->password,
            'locale' => $admin->locale ? (string) $admin->locale : 'ar',
        ];
    }

    /**
     * @param  array{email: string, name: string, password: string, locale: string|null}|null  $snapshot
     */
    private function restoreAdmin(?array $snapshot, string $email, mixed $newPassword): User
    {
        $password = filled($newPassword)
            ? Hash::make((string) $newPassword)
            : ($snapshot['password'] ?? Hash::make('password'));

        $user = User::query()->updateOrCreate(
            ['email' => $snapshot['email'] ?? $email],
            [
                'name' => $snapshot['name'] ?? 'Science Street Admin',
                'password' => $password,
                'locale' => $snapshot['locale'] ?? 'ar',
                'email_verified_at' => now(),
            ],
        );

        if (method_exists($user, 'assignRole')) {
            $user->assignRole('super_admin');
        }

        return $user;
    }
}
