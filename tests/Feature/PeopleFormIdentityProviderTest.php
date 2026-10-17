<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\People\Pages\EditPeople;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PeopleFormIdentityProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_field_is_hidden_for_user_synced_from_identity_provider(): void
    {
        [$tenant, $person] = $this->signInAsTenantAdmin([
            'entra_object_id' => '11111111-1111-1111-1111-111111111111',
            'password' => null,
        ]);

        try {
            Livewire::test(EditPeople::class, ['tenant' => $tenant, 'record' => $person->getKey()])
                ->assertOk()
                ->assertFormFieldHidden('password');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function test_password_field_is_shown_for_local_user(): void
    {
        [$tenant, $person] = $this->signInAsTenantAdmin();

        try {
            Livewire::test(EditPeople::class, ['tenant' => $tenant, 'record' => $person->getKey()])
                ->assertOk()
                ->assertFormFieldVisible('password');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @param  array<string, mixed>  $personAttributes
     * @return array{0: Tenant, 1: User}
     */
    private function signInAsTenantAdmin(array $personAttributes = []): array
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');

        $person = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            ...$personAttributes,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);

        return [$tenant, $person];
    }
}
