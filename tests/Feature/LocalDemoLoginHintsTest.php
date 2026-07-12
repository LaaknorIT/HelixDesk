<?php

namespace Tests\Feature;

use App\Support\LocalDemoLogins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDemoLoginHintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_hints_are_hidden_outside_local_environment(): void
    {
        $this->assertFalse(LocalDemoLogins::isEnabled());

        $this->get('/global-admin/login')
            ->assertOk()
            ->assertDontSee('Local development accounts')
            ->assertDontSee('demo-globaladmin@helixdesk.local');

        $this->get('/agent/login')
            ->assertOk()
            ->assertDontSee('demo-agent@helixdesk.local');

        $this->get('/end-user/login')
            ->assertOk()
            ->assertDontSee('demo-enduser1@helixdesk.local');
    }

    public function test_demo_login_hints_are_shown_on_each_panel_in_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->assertTrue(LocalDemoLogins::isEnabled());

        $this->get('/global-admin/login')
            ->assertOk()
            ->assertSee('Local development accounts')
            ->assertSee('demo-globaladmin@helixdesk.local')
            ->assertSee('password')
            ->assertDontSee('demo-agent@helixdesk.local');

        $this->get('/agent/login')
            ->assertOk()
            ->assertSee('Local development accounts')
            ->assertSee('demo-agent@helixdesk.local')
            ->assertSee('demo-tenantadmin@helixdesk.local')
            ->assertSee('demo-tenant')
            ->assertDontSee('demo-globaladmin@helixdesk.local');

        $this->get('/end-user/login')
            ->assertOk()
            ->assertSee('Local development accounts')
            ->assertSee('demo-enduser1@helixdesk.local')
            ->assertSee('demo-enduser2@helixdesk.local')
            ->assertSee('demo-tenant')
            ->assertDontSee('demo-agent@helixdesk.local');
    }

    public function test_local_demo_logins_helper_filters_accounts_by_panel(): void
    {
        $this->app['env'] = 'local';

        $this->assertSame(
            ['demo-globaladmin@helixdesk.local'],
            array_column(LocalDemoLogins::accountsForPanel('global-admin'), 'email'),
        );

        $this->assertSame(
            ['demo-agent@helixdesk.local', 'demo-tenantadmin@helixdesk.local'],
            array_column(LocalDemoLogins::accountsForPanel('agent'), 'email'),
        );

        $this->assertSame(
            ['demo-enduser1@helixdesk.local', 'demo-enduser2@helixdesk.local'],
            array_column(LocalDemoLogins::accountsForPanel('end-user'), 'email'),
        );
    }
}
