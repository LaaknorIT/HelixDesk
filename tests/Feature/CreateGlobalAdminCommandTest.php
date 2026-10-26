<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateGlobalAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_global_admin_from_options(): void
    {
        $this->artisan('helixdesk:create-admin', [
            '--name' => 'Ops Admin',
            '--email' => 'ops@example.com',
            '--password' => 'correct-horse-battery',
        ])
            ->expectsOutputToContain('Global administrator ops@example.com created')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ops@example.com')->sole();

        $this->assertSame('Ops Admin', $user->name);
        $this->assertTrue($user->is_admin);
        $this->assertSame('global_admin', $user->type);
        $this->assertNull($user->tenant_id);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertTrue($user->canAccessPanel(Panel::make()->id('global-admin')));
    }

    public function test_it_prompts_for_missing_values_when_interactive(): void
    {
        $this->artisan('helixdesk:create-admin')
            ->expectsQuestion('Name', 'Prompted Admin')
            ->expectsQuestion('Email', 'prompted@example.com')
            ->expectsQuestion('Password', 'another-long-password')
            ->assertSuccessful();

        $this->assertDatabaseHas(User::class, [
            'email' => 'prompted@example.com',
            'is_admin' => true,
        ]);
    }

    public function test_it_fails_without_values_when_not_interactive(): void
    {
        $this->artisan('helixdesk:create-admin', ['--no-interaction' => true])
            ->assertFailed();

        $this->assertDatabaseCount(User::class, 0);
    }

    public function test_it_rejects_an_email_that_is_already_in_use(): void
    {
        $existing = User::factory()->create([
            'email' => 'taken@example.com',
            'is_admin' => false,
        ]);

        $this->artisan('helixdesk:create-admin', [
            '--name' => 'Someone',
            '--email' => 'taken@example.com',
            '--password' => 'correct-horse-battery',
        ])
            ->expectsOutputToContain('already been taken')
            ->assertFailed();

        $this->assertFalse($existing->fresh()->is_admin);
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->artisan('helixdesk:create-admin', [
            '--name' => 'Someone',
            '--email' => 'short@example.com',
            '--password' => 'short',
        ])
            ->expectsOutputToContain('at least 12 characters')
            ->assertFailed();

        $this->assertDatabaseMissing(User::class, ['email' => 'short@example.com']);
    }
}
