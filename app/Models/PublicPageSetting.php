<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Content of the public front page, managed by global admins.
 *
 * There is one row. Until an admin saves the page, {@see self::current()} returns unsaved defaults.
 * Every block except the headline, introduction and sign-in link stays hidden until an admin makes it public.
 *
 * @property string $headline
 * @property string|null $introduction
 * @property bool $show_announcement
 * @property string|null $announcement_title
 * @property string|null $announcement_body
 * @property bool $show_contact
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $contact_hours
 * @property list<array{title: string|null, body: string|null, is_public: bool}>|null $sections
 */
class PublicPageSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'headline',
        'introduction',
        'show_announcement',
        'announcement_title',
        'announcement_body',
        'show_contact',
        'contact_email',
        'contact_phone',
        'contact_hours',
        'sections',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_announcement' => 'boolean',
            'show_contact' => 'boolean',
            'sections' => 'array',
        ];
    }

    /**
     * The saved front page, or the defaults when nothing has been saved yet.
     */
    public static function current(): self
    {
        return static::query()->oldest('id')->first() ?? static::defaults();
    }

    public static function defaults(): self
    {
        $appName = (string) config('app.name', 'HelixDesk');

        return new self([
            'headline' => __('Welcome to :name', ['name' => $appName]),
            'introduction' => __('Sign in to report an issue, ask for help, and follow your requests until they are solved.'),
            'show_announcement' => false,
            'show_contact' => false,
            'sections' => [
                [
                    'title' => __('Report an issue'),
                    'body' => __('Tell us what is wrong or what you need. Your request gets a reference number, so you can follow it from start to finish.'),
                    'is_public' => true,
                ],
                [
                    'title' => __('Follow your requests'),
                    'body' => __('See the status of everything you have reported, and reply to the people working on it.'),
                    'is_public' => true,
                ],
                [
                    'title' => __('For IT staff'),
                    'body' => __('Agents sign in with the same button and go straight to their workspace.'),
                    'is_public' => true,
                ],
            ],
        ]);
    }

    /**
     * Sections an admin has marked as public and that have something to show.
     *
     * @return list<array{title: string|null, body: string|null}>
     */
    public function publicSections(): array
    {
        $sections = array_filter(
            $this->sections ?? [],
            fn (array $section): bool => (bool) ($section['is_public'] ?? false)
                && (filled($section['title'] ?? null) || filled($section['body'] ?? null)),
        );

        return array_values(array_map(
            fn (array $section): array => [
                'title' => $section['title'] ?? null,
                'body' => $section['body'] ?? null,
            ],
            $sections,
        ));
    }

    public function hasPublicAnnouncement(): bool
    {
        return $this->show_announcement
            && (filled($this->announcement_title) || filled($this->announcement_body));
    }

    public function hasPublicContact(): bool
    {
        return $this->show_contact
            && (filled($this->contact_email) || filled($this->contact_phone) || filled($this->contact_hours));
    }
}
