<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Enums\Width;
use Tests\TestCase;

class PanelLayoutWidthTest extends TestCase
{
    public function test_staff_panels_use_the_full_screen_width(): void
    {
        $this->assertSame(Width::Full, Filament::getPanel('agent')->getMaxContentWidth());
        $this->assertSame(Width::Full, Filament::getPanel('global-admin')->getMaxContentWidth());
    }

    public function test_end_user_panel_keeps_a_readable_content_width(): void
    {
        $this->assertSame(Width::ScreenTwoExtraLarge, Filament::getPanel('end-user')->getMaxContentWidth());
    }

    public function test_customer_record_navigation_sits_above_the_page_instead_of_beside_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('agent'));

        $this->assertSame(SubNavigationPosition::Top, OrganizationResource::getSubNavigationPosition());
    }

    public function test_theme_wraps_wizard_steps_and_tabs_instead_of_scrolling_sideways(): void
    {
        $theme = (string) file_get_contents(resource_path('css/filament/theme.css'));

        $this->assertStringContainsString('.fi-sc-wizard .fi-sc-wizard-header', $theme);
        $this->assertStringContainsString('md:overflow-x-visible', $theme);
        $this->assertStringContainsString('.fi-tabs:not(.fi-vertical)', $theme);
        $this->assertStringContainsString('flex-wrap', $theme);
    }
}
