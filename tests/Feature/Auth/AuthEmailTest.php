<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_sends_verification_notification_and_user_starts_unverified(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Student',
            'email' => 'new-student@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $response->assertJsonPath('data.user.email_verified', false);

        $user = User::query()->where('email', 'new-student@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertInstanceOf(ShouldQueue::class, new VerifyEmailNotification);
    }

    public function test_valid_verification_link_verifies_user(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($url)->assertOk()->assertJsonPath('data.email_verified', true);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_invalid_verification_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1('wrong@example.com')],
        );

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($url)->assertForbidden();
    }

    public function test_already_verified_user_gets_success_response(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($url)->assertOk()->assertJsonPath('data.email_verified', true);
    }

    public function test_resend_verification_works_for_unverified_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_resend_verification_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/email/verification-notification')->assertOk();
        }

        $this->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429);
    }

    public function test_forgot_password_sends_reset_notification_without_email_enumeration(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk();

        $user = User::factory()->create(['email' => 'exists@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'exists@example.com',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_changes_password_and_invalidates_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertFalse(Hash::check('OldPassword123!', $user->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'AnotherPassword123!',
            'password_confirmation' => 'AnotherPassword123!',
        ])->assertStatus(422);
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => 'invalid-token',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422);
    }

    public function test_auth_me_includes_email_verified_flag(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);
    }

    public function test_verification_and_reset_notifications_are_queueable(): void
    {
        Queue::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class);

        $token = Password::createToken($user);
        $user->sendPasswordResetNotification($token);

        Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class);
    }
}
