<?php

namespace App\Filament\GlobalAdmin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\PublicPageSetting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Lets global admins decide what the public front page shows.
 *
 * The headline, introduction and sign-in link are always shown. Announcement, contact details
 * and each section are only shown once they are switched to public.
 */
class ManagePublicPage extends Page
{
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Public front page';

    protected static ?string $title = 'Public front page';

    protected static ?string $slug = 'public-front-page';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(PublicPageSetting::current()->only([
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
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Welcome'))
                    ->description(__('Always shown, together with the sign-in button.'))
                    ->schema([
                        TextInput::make('headline')
                            ->required()
                            ->maxLength(255),
                        MarkdownEditor::make('introduction')
                            ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']])
                            ->maxLength(5000),
                    ]),
                Section::make(__('Announcement'))
                    ->description(__('A highlighted notice, for example planned maintenance or an ongoing outage.'))
                    ->schema([
                        Toggle::make('show_announcement')
                            ->label(__('Show on the public page'))
                            ->live(),
                        TextInput::make('announcement_title')
                            ->label(__('Title'))
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('show_announcement') && blank($get('announcement_body'))),
                        MarkdownEditor::make('announcement_body')
                            ->label(__('Text'))
                            ->toolbarButtons([['bold', 'italic', 'link']])
                            ->maxLength(5000),
                    ]),
                Section::make(__('Contact details'))
                    ->description(__('Only shown when switched on. Leave a field empty to hide it.'))
                    ->schema([
                        Toggle::make('show_contact')
                            ->label(__('Show on the public page')),
                        TextInput::make('contact_email')
                            ->label(__('E-mail'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('contact_phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('contact_hours')
                            ->label(__('Opening hours'))
                            ->placeholder(__('Mon–Fri 08:00–16:00'))
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make(__('Sections'))
                    ->description(__('Information blocks shown below the welcome text. Hidden sections are kept so you can publish them later.'))
                    ->schema([
                        Repeater::make('sections')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                MarkdownEditor::make('body')
                                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']])
                                    ->maxLength(5000),
                                Toggle::make('is_public')
                                    ->label(__('Public'))
                                    ->default(true),
                            ])
                            ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null)
                                ? $state['title'].(($state['is_public'] ?? false) ? '' : ' (hidden)')
                                : null)
                            ->addActionLabel(__('Add section'))
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->defaultItems(0),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label(__('Save'))
                                ->submit('save'),
                            Action::make('view')
                                ->label(__('View public page'))
                                ->color('gray')
                                ->url(route('home'))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $state['sections'] = array_values(array_map(
            fn (array $section): array => [
                'title' => $section['title'] ?? null,
                'body' => $section['body'] ?? null,
                'is_public' => (bool) ($section['is_public'] ?? false),
            ],
            $state['sections'] ?? [],
        ));

        $page = PublicPageSetting::query()->oldest('id')->first() ?? new PublicPageSetting;
        $page->fill($state)->save();

        Notification::make()
            ->title(__('Public front page saved'))
            ->success()
            ->send();
    }
}
