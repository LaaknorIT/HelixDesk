<?php

namespace App\Services\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;

/**
 * Decides where a user lands after signing in on the shared login page.
 *
 * Users who can use several panels go to the most capable one: agents (and tenant admins)
 * land in the agent panel even when they also have end-user access.
 */
class HomePanelResolver
{
    /**
     * Panel ids in the order they are preferred.
     *
     * @var list<string>
     */
    public const PANEL_PRIORITY = ['agent', 'global-admin', 'end-user'];

    /**
     * The panel a user should land in, or null when they cannot use any panel.
     */
    public function panelFor(User $user): ?Panel
    {
        foreach ($this->accessiblePanels($user) as $panel) {
            return $panel;
        }

        return null;
    }

    /**
     * Panels the user may use, most preferred first.
     *
     * @return list<Panel>
     */
    public function accessiblePanels(User $user): array
    {
        $panels = [];

        foreach (self::PANEL_PRIORITY as $panelId) {
            $panel = rescue(fn (): Panel => Filament::getPanel($panelId, isStrict: true), null, report: false);

            if ($panel instanceof Panel && $user->canAccessPanel($panel)) {
                $panels[] = $panel;
            }
        }

        return $panels;
    }

    /**
     * Where to send a user who just signed in: the page they were trying to open when it is in a
     * panel they may use, otherwise the home of their preferred panel.
     */
    public function destinationFor(User $user, ?string $intendedUrl = null): ?string
    {
        $panels = $this->accessiblePanels($user);

        if ($panels === []) {
            return null;
        }

        if (filled($intendedUrl) && $this->isInsideAnyPanel($intendedUrl, $panels)) {
            return $intendedUrl;
        }

        return $this->homeUrl($panels[0], $user);
    }

    public function homeUrl(Panel $panel, User $user): string
    {
        $tenant = $panel->hasTenancy()
            ? collect($user->getTenants($panel))->first(fn ($tenant): bool => $user->canAccessTenant($tenant))
            : null;

        return $panel->getUrl($tenant) ?? url($panel->getPath());
    }

    /**
     * @param  list<Panel>  $panels
     */
    private function isInsideAnyPanel(string $url, array $panels): bool
    {
        $appHost = parse_url(url('/'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        if ($host !== null && $host !== $appHost) {
            return false;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach ($panels as $panel) {
            $panelPath = trim($panel->getPath(), '/');

            if ($path === $panelPath || str_starts_with($path, $panelPath.'/')) {
                return ! str_starts_with($path, $panelPath.'/login');
            }
        }

        return false;
    }
}
