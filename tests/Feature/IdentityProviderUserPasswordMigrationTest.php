<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityProviderUserPasswordMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_removes_passwords_from_synced_users_only(): void
    {
        $tenant = Tenant::factory()->create();

        $syncedUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
            'password' => 'random-password-from-old-sync',
        ]);

        $localUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
            'password' => 'local-password',
        ]);

        $migration = require database_path('migrations/2026_10_17_171603_make_password_nullable_for_identity_provider_users.php');
        $migration->up();

        $this->assertNull($syncedUser->fresh()->password);
        $this->assertNotNull($localUser->fresh()->password);
        $this->assertTrue(auth()->validate(['email' => $localUser->email, 'password' => 'local-password']));
    }
}
