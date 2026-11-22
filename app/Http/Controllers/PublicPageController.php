<?php

namespace App\Http\Controllers;

use App\Models\PublicPageSetting;
use App\Models\User;
use App\Services\Auth\HomePanelResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public front page. Global admins decide what it shows under "Public front page".
 */
class PublicPageController extends Controller
{
    public function __invoke(Request $request, HomePanelResolver $homePanels): View
    {
        $page = PublicPageSetting::current();
        $user = $request->user();

        return view('public-page', [
            'page' => $page,
            'introduction' => $this->markdown($page->introduction),
            'announcementBody' => $page->hasPublicAnnouncement() ? $this->markdown($page->announcement_body) : null,
            'sections' => array_map(
                fn (array $section): array => [
                    'title' => $section['title'],
                    'body' => $this->markdown($section['body']),
                ],
                $page->publicSections(),
            ),
            'workspaceUrl' => $user instanceof User ? $homePanels->destinationFor($user) : null,
        ]);
    }

    /**
     * Admin-written Markdown with raw HTML and unsafe links removed.
     */
    private function markdown(?string $text): ?string
    {
        if (blank($text)) {
            return null;
        }

        return Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
