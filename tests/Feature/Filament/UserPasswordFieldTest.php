<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class UserPasswordFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_edit_user_page_loads_with_empty_revealable_password_field(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'password' => Hash::make('original-user-secret'),
        ]);
        $storedHash = $user->getRawOriginal('password');

        $this->actingAs($admin)
            ->get('/admin/users/'.$user->getRouteKey().'/edit')
            ->assertOk()
            ->assertSee(__('admin.users.fields.password'), false)
            ->assertSee(__('admin.users.fields.password_keep_help'), false)
            ->assertSee('isPasswordRevealed', false)
            ->assertDontSee($storedHash, false)
            ->assertDontSee('original-user-secret', false);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSuccessful()
            ->assertFormFieldExists('password')
            ->assertFormSet(['password' => null])
            ->assertSee(__('admin.users.fields.password_keep_help'))
            ->assertSee(__('admin.users.fields.password_generate'))
            ->assertSeeHtml('isPasswordRevealed')
            ->assertDontSee($storedHash);
    }

    public function test_password_field_uses_native_filament_revealable_api(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('isPasswordRevealed')
            ->assertSeeHtml("isPasswordRevealed = true");

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->assertSuccessful()
            ->assertSeeHtml('isPasswordRevealed')
            ->assertSeeHtml("isPasswordRevealed = true")
            ->assertSee(__('admin.users.fields.password_generate'));
    }

    public function test_generate_password_action_fills_a_strong_plaintext_value(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'password' => Hash::make('original-user-secret'),
        ]);
        $originalHash = $user->getRawOriginal('password');

        $component = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormSet(['password' => null])
            ->callFormComponentAction('password', 'generatePassword')
            ->assertHasNoFormErrors();

        $generated = $component->get('data.password');
        $this->assertIsString($generated);
        $this->assertGreaterThanOrEqual(16, strlen($generated));
        $this->assertNotSame('original-user-secret', $generated);
        $this->assertNotSame($originalHash, $generated);
        $this->assertFalse(Hash::isHashed($generated));
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $generated);
        $this->assertMatchesRegularExpression('/[0-9]/', $generated);

        $component->call('save')->assertHasNoFormErrors();

        $user->refresh();
        $stored = $user->getRawOriginal('password');
        $this->assertNotSame($generated, $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check($generated, $stored));
        $this->assertFalse(Hash::check('original-user-secret', $stored));
        $this->assertNotSame($originalHash, $stored);
    }

    public function test_saving_edit_with_empty_password_preserves_existing_hash(): void
    {
        $admin = $this->admin();
        $adminHash = $admin->getRawOriginal('password');
        $user = User::factory()->create([
            'name' => 'Keep Password User',
            'password' => Hash::make('original-user-secret'),
        ]);
        $unrelated = User::factory()->create([
            'password' => Hash::make('unrelated-secret'),
        ]);
        $originalHash = $user->getRawOriginal('password');
        $unrelatedHash = $unrelated->getRawOriginal('password');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed Keep Password User',
                'password' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $admin->refresh();
        $unrelated->refresh();

        $this->assertSame('Renamed Keep Password User', $user->name);
        $this->assertSame($originalHash, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('original-user-secret', $user->password));
        $this->assertSame($adminHash, $admin->getRawOriginal('password'));
        $this->assertSame($unrelatedHash, $unrelated->getRawOriginal('password'));
    }

    public function test_entering_a_new_password_hashes_once_and_replaces_the_old_one(): void
    {
        $admin = $this->admin();
        $adminHash = $admin->getRawOriginal('password');
        $user = User::factory()->create([
            'password' => Hash::make('original-user-secret'),
        ]);
        $unrelated = User::factory()->create([
            'password' => Hash::make('unrelated-secret'),
        ]);
        $unrelatedHash = $unrelated->getRawOriginal('password');

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'password' => 'replacement-user-secret',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $admin->refresh();
        $unrelated->refresh();
        $stored = $user->getRawOriginal('password');

        $this->assertNotSame('replacement-user-secret', $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check('replacement-user-secret', $stored));
        $this->assertFalse(Hash::check('original-user-secret', $stored));
        $this->assertFalse(Hash::check('replacement-user-secret', Hash::make($stored)));
        $this->assertTrue(auth()->attempt([
            'email' => $user->email,
            'password' => 'replacement-user-secret',
        ]));
        $this->assertFalse(auth()->attempt([
            'email' => $user->email,
            'password' => 'original-user-secret',
        ]));
        $this->assertSame($adminHash, $admin->getRawOriginal('password'));
        $this->assertSame($unrelatedHash, $unrelated->getRawOriginal('password'));
    }

    public function test_create_user_still_requires_and_hashes_password(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Created Customer',
                'email' => 'created-customer@example.test',
                'password' => null,
                'locale' => 'ar',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Created Customer',
                'email' => 'created-customer@example.test',
                'password' => 'created-user-secret',
                'locale' => 'ar',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'created-customer@example.test')->first();
        $this->assertNotNull($created);
        $stored = $created->getRawOriginal('password');
        $this->assertNotSame('created-user-secret', $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check('created-user-secret', $stored));
        $this->assertTrue(auth()->attempt([
            'email' => 'created-customer@example.test',
            'password' => 'created-user-secret',
        ]));
    }

    public function test_user_without_admin_role_cannot_open_user_edit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/users/'.$user->getRouteKey().'/edit')
            ->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'password' => Hash::make('admin-login-secret'),
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }
}
