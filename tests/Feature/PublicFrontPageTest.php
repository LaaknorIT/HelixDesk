<?php

namespace Tests\Feature;

use App\Filament\GlobalAdmin\Pages\ManagePublicPage;
use App\Models\PublicPageSetting;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicFrontPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_page_shows_default_content_and_a_sign_in_link_before_anything_is_saved(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('Welcome to :name', ['name' => config('app.name')]))
            ->assertSee('Report an issue')
            ->assertSee(route('login'), escape: false)
            ->assertDontSee('Contact us');
    }

    public function test_only_public_content_is_shown(): void
    {
        PublicPageSetting::query()->create([
            'headline' => 'Acme IT service desk',
            'introduction' => 'We keep **your** computers running.',
            'show_announcement' => true,
            'announcement_title' => 'Planned maintenance',
            'announcement_body' => 'Mail is down Saturday night.',
            'show_contact' => false,
            'contact_email' => 'secret-desk@example.test',
            'contact_phone' => '+47 12 34 56 78',
            'sections' => [
                ['title' => 'Opening hours', 'body' => 'Weekdays 8–16', 'is_public' => true],
                ['title' => 'Internal escalation list', 'body' => 'Call the CTO', 'is_public' => false],
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Acme IT service desk')
            ->assertSee('<strong>your</strong>', escape: false)
            ->assertSee('Planned maintenance')
            ->assertSee('Opening hours')
            ->assertDontSee('Internal escalation list')
            ->assertDontSee('Call the CTO')
            ->assertDontSee('secret-desk@example.test')
            ->assertDontSee('Report an issue');
    }

    public function test_contact_details_are_shown_when_public(): void
    {
        PublicPageSetting::query()->create([
            'headline' => 'Service desk',
            'show_contact' => true,
            'contact_email' => 'desk@example.test',
            'contact_phone' => '+47 12 34 56 78',
            'sections' => [],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Contact us')
            ->assertSee('desk@example.test')
            ->assertSee('tel:+4712345678', escape: false);
    }

    public function test_admin_written_html_is_escaped(): void
    {
        PublicPageSetting::query()->create([
            'headline' => 'Desk',
            'introduction' => '<script>alert(1)</script> [link](javascript:alert(1))',
            'sections' => [],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertDontSee('href="javascript:', escape: false);
    }

    public function test_signed_in_user_gets_a_link_to_their_workspace(): void
    {
        $tenant = Tenant::factory()->create();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'agent']);

        $this->actingAs($agent)
            ->get('/')
            ->assertOk()
            ->assertSee('Go to your workspace')
            ->assertSee(url("/agent/{$tenant->slug}"), escape: false);
    }

    public function test_global_admin_can_manage_the_front_page(): void
    {
        $admin = User::factory()->create(['tenant_id' => null, 'type' => 'global_admin', 'is_admin' => true]);

        $this->actingAs($admin)->get('/global-admin/public-front-page')->assertOk();

        Filament::setCurrentPanel('global-admin');

        Livewire::test(ManagePublicPage::class)
            ->fillForm([
                'headline' => 'Acme service desk',
                'introduction' => 'Hello',
                'show_contact' => true,
                'contact_email' => 'desk@example.test',
                'sections' => [
                    ['title' => 'Visible', 'body' => 'Shown', 'is_public' => true],
                    ['title' => 'Draft', 'body' => 'Not yet', 'is_public' => false],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Public front page saved');

        $page = PublicPageSetting::current();
        $this->assertTrue($page->exists);
        $this->assertSame('Acme service desk', $page->headline);
        $this->assertSame([['title' => 'Visible', 'body' => 'Shown']], $page->publicSections());

        $this->get('/')->assertSee('Acme service desk')->assertSee('Visible')->assertDontSee('Not yet');
    }

    public function test_tenant_staff_cannot_manage_the_front_page(): void
    {
        $agent = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'type' => 'agent']);

        $this->actingAs($agent)->get('/global-admin/public-front-page')->assertForbidden();
        $this->assertFalse(ManagePublicPage::canAccess());
    }
}
