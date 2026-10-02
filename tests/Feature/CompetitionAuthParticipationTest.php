<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionParticipant;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CompetitionAuthParticipationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_eligibility_and_dashboard_are_unauthenticated(): void
    {
        $this->seed();

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertUnauthorized();

        $this->getJson('/api/v1/competitions/microscope-100-challenge/dashboard')
            ->assertUnauthorized();
    }

    public function test_authenticated_eligible_nonparticipant_gets_register_state_not_login(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->completeMicroscopeCourse($user);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.can_register', true)
            ->assertJsonPath('data.state', 'ELIGIBLE_TO_REGISTER');

        $this->getJson('/api/v1/competitions/microscope-100-challenge/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_registered')
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.can_register', true)
            ->assertJsonMissingPath('data.login_required');
    }

    public function test_authenticated_participant_gets_dashboard(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->completeMicroscopeCourse($user);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')->assertCreated();

        $this->getJson('/api/v1/competitions/microscope-100-challenge/dashboard')
            ->assertOk()
            ->assertJsonPath('data.participation.registered', true)
            ->assertJsonPath('data.participation.state', 'REGISTERED_PARTICIPANT');
    }

    public function test_authenticated_missing_prerequisite_is_ineligible(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.prerequisite_satisfied', false)
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.state', 'INELIGIBLE')
            ->assertJsonPath('data.reason', 'course_not_completed');
    }

    public function test_active_enrollment_counts_as_prerequisite_access(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();
        Enrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $competition->prerequisite_course_id,
            'status' => EnrollmentStatus::Active,
            'progress_percent' => 10,
            'enrolled_at' => now(),
        ]);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.prerequisite_satisfied', true);
    }

    public function test_customer_bearer_wins_over_admin_web_session_for_competition(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@sciencestreetlab.com')->firstOrFail();
        $customer = User::factory()->create();
        $this->completeMicroscopeCourse($customer);

        $this->actingAs($admin, 'web');
        $this->assertSame($admin->id, Auth::guard('web')->id());

        $token = $customer->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.authenticated', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_registration_is_idempotent_and_does_not_duplicate_participant(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->completeMicroscopeCourse($user);

        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')->assertCreated();
        $this->postJson('/api/v1/competitions/microscope-100-challenge/register')->assertOk();

        $this->assertSame(
            1,
            CompetitionParticipant::query()->where('user_id', $user->id)->count()
        );
    }

    public function test_frontend_canonical_slug_resolves_production_arabic_alias(): void
    {
        $this->seed();
        $competition = Competition::query()->where('slug', 'microscope-100-challenge')->firstOrFail();
        $competition->update([
            'slug' => 'thdy-al100-sor-bastkhdam-mykroskob-sharaa-alaalom',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->completeMicroscopeCourse($user);

        $this->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user_id', $user->id);

        $this->getJson('/api/v1/competitions/microscope-100-challenge')
            ->assertOk()
            ->assertJsonPath('data.slug', 'thdy-al100-sor-bastkhdam-mykroskob-sharaa-alaalom');
    }

    public function test_auth_me_and_competition_resolve_same_user(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data.id');

        $compUser = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/competitions/microscope-100-challenge/eligibility')
            ->assertOk()
            ->json('data.user_id');

        $this->assertSame($me, $compUser);
        $this->assertSame($user->id, $compUser);
    }

    private function completeMicroscopeCourse(User $user): void
    {
        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $topic = Topic::query()->where('slug', 'what-is-microscope')->firstOrFail();
        $quiz = Quiz::query()->where('quizable_id', $topic->lesson_id)->firstOrFail();
        $correctOption = QuestionOption::query()
            ->where('question_id', $quiz->questions()->first()->id)
            ->where('is_correct', true)
            ->firstOrFail();

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id]);
        $orderId = $this->postJson('/api/v1/checkout', [
            'billing_address' => [
                'first_name' => 'Student',
                'last_name' => 'Test',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '123 Test Street',
                'district' => 'Nasr City',
                'district_name' => 'Nasr City',
                'bosta_district_id' => 'district-nasr',
                'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
            ],
            'shipping_address' => [
                'first_name' => 'Student',
                'last_name' => 'Test',
                'email' => $user->email,
                'phone' => '01012345678',
                'city' => 'Cairo',
                'country' => 'EG',
                'address' => '123 Test Street',
                'district' => 'Nasr City',
                'district_name' => 'Nasr City',
                'bosta_district_id' => 'district-nasr',
                'bosta_city_id' => 'FceDyHXwpSYYF9zGW',
            ],
        ])->json('data.id');

        $paymentId = $this->postJson("/api/v1/checkout/{$orderId}/pay")->json('data.payment_id');
        $this->postJson("/api/v1/payments/mock/{$paymentId}/complete");

        $this->postJson("/api/v1/topics/{$topic->id}/progress", ['watch_progress_percent' => 95]);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->json('data.id');
        $this->postJson("/api/v1/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $quiz->questions()->first()->id, 'selected_option_ids' => [$correctOption->id]],
            ],
        ]);
    }
}
