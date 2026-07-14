<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Filament\Agent\Resources\Changes\ChangeResource;
use App\Filament\Agent\Resources\DashboardLayouts\DashboardLayoutResource;
use App\Filament\Agent\Resources\Documents\DocumentResource;
use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use App\Filament\Agent\Resources\MonitoringEvents\MonitoringEventResource;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\People\PeopleResource;
use App\Filament\Agent\Resources\Problems\ProblemResource;
use App\Filament\Agent\Resources\Queues\QueueResource;
use App\Filament\Agent\Resources\ServiceOfferings\ServiceOfferingResource;
use App\Filament\Agent\Resources\Suppliers\SupplierResource;
use App\Filament\Agent\Resources\TenantRoles\TenantRoleResource;
use App\Filament\Agent\Resources\WorkItems\WorkItemResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPanelNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_registers_navigation_groups_in_order(): void
    {
        $labels = $this->registeredNavigationGroupLabels();

        $this->assertSame([
            'Customers',
            'Service management',
            'Infrastructure',
            'Knowledge',
            'Administration',
        ], $labels);
    }

    public function test_customers_navigation_group_is_expanded_and_secondary_groups_are_collapsed(): void
    {
        $collapsedByLabel = $this->registeredNavigationGroupCollapsedState();

        $this->assertFalse($collapsedByLabel['Customers']);
        $this->assertTrue($collapsedByLabel['Service management']);
        $this->assertTrue($collapsedByLabel['Infrastructure']);
        $this->assertTrue($collapsedByLabel['Knowledge']);
        $this->assertTrue($collapsedByLabel['Administration']);
    }

    public function test_resources_expose_expected_navigation_metadata(): void
    {
        $this->assertNull(WorkItemResource::getNavigationGroup());
        $this->assertSame(1, WorkItemResource::getNavigationSort());

        $this->assertNull(MonitoringEventResource::getNavigationGroup());
        $this->assertSame(2, MonitoringEventResource::getNavigationSort());

        $this->assertSame('Customers', OrganizationResource::getNavigationGroup());
        $this->assertSame(10, OrganizationResource::getNavigationSort());

        $this->assertSame('Customers', PeopleResource::getNavigationGroup());
        $this->assertSame(11, PeopleResource::getNavigationSort());

        $this->assertSame('Service management', ChangeResource::getNavigationGroup());
        $this->assertSame(20, ChangeResource::getNavigationSort());

        $this->assertSame('Service management', ProblemResource::getNavigationGroup());
        $this->assertSame(21, ProblemResource::getNavigationSort());

        $this->assertSame('Infrastructure', AssetResource::getNavigationGroup());
        $this->assertSame(30, AssetResource::getNavigationSort());

        $this->assertSame('Infrastructure', ServiceOfferingResource::getNavigationGroup());
        $this->assertSame(31, ServiceOfferingResource::getNavigationSort());
        $this->assertSame(Heroicon::OutlinedBriefcase, ServiceOfferingResource::getNavigationIcon());

        $this->assertSame('Infrastructure', SupplierResource::getNavigationGroup());
        $this->assertSame(32, SupplierResource::getNavigationSort());

        $this->assertSame('Knowledge', DocumentResource::getNavigationGroup());
        $this->assertSame('Documents', DocumentResource::getNavigationLabel());
        $this->assertSame(40, DocumentResource::getNavigationSort());

        $this->assertSame('Administration', QueueResource::getNavigationGroup());
        $this->assertSame(50, QueueResource::getNavigationSort());

        $this->assertSame('Administration', MailAccountResource::getNavigationGroup());
        $this->assertSame(51, MailAccountResource::getNavigationSort());

        $this->assertSame('Administration', TenantRoleResource::getNavigationGroup());
        $this->assertSame(52, TenantRoleResource::getNavigationSort());

        $this->assertSame('Administration', DashboardLayoutResource::getNavigationGroup());
        $this->assertSame(53, DashboardLayoutResource::getNavigationSort());
        $this->assertSame(Heroicon::OutlinedViewColumns, DashboardLayoutResource::getNavigationIcon());
    }

    public function test_tenant_admin_navigation_includes_reorganized_groups_and_top_level_items(): void
    {
        $tenant = Tenant::factory()->create();

        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $tenantAdmin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        $this->actingAs($tenantAdmin);

        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $navigation = Filament::getNavigation();

            $topLevelLabels = $this->navigationItemLabelsForGroup($navigation, null);
            $this->assertContains('Dashboard', $topLevelLabels);
            $this->assertContains('Tickets', $topLevelLabels);
            $this->assertContains('Monitoring events', $topLevelLabels);

            $this->assertSame(
                ['Customers', 'People'],
                $this->navigationItemLabelsForGroup($navigation, 'Customers'),
            );

            $this->assertSame(
                ['Changes', 'Problems'],
                $this->navigationItemLabelsForGroup($navigation, 'Service management'),
            );

            $this->assertSame(
                ['Assets', 'Service offerings', 'Suppliers'],
                $this->navigationItemLabelsForGroup($navigation, 'Infrastructure'),
            );

            $this->assertSame(
                ['Documents'],
                $this->navigationItemLabelsForGroup($navigation, 'Knowledge'),
            );

            $this->assertSame(
                ['Queues', 'Mail accounts', 'Roles', 'Dashboard layouts'],
                $this->navigationItemLabelsForGroup($navigation, 'Administration'),
            );
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @return list<string>
     */
    private function registeredNavigationGroupLabels(): array
    {
        return collect(Filament::getPanel('agent')->getNavigationGroups())
            ->map(function (NavigationGroup|string $group): string {
                return $group instanceof NavigationGroup ? (string) $group->getLabel() : (string) $group;
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function registeredNavigationGroupCollapsedState(): array
    {
        return collect(Filament::getPanel('agent')->getNavigationGroups())
            ->mapWithKeys(function (NavigationGroup|string $group): array {
                if (! $group instanceof NavigationGroup) {
                    return [(string) $group => false];
                }

                return [(string) $group->getLabel() => $group->isCollapsed()];
            })
            ->all();
    }

    /**
     * @param  array<NavigationGroup>  $navigation
     * @return list<string>
     */
    private function navigationItemLabelsForGroup(array $navigation, ?string $groupLabel): array
    {
        foreach ($navigation as $group) {
            if ($groupLabel === null && blank($group->getLabel())) {
                return collect($group->getItems())
                    ->map(fn ($item) => $item->getLabel())
                    ->values()
                    ->all();
            }

            if ($group->getLabel() === $groupLabel) {
                return collect($group->getItems())
                    ->map(fn ($item) => $item->getLabel())
                    ->values()
                    ->all();
            }
        }

        $this->fail("Navigation group [{$groupLabel}] was not found.");
    }
}
