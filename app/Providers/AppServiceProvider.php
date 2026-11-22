<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Observers\TenantObserver;
use App\Observers\WorkItemObserver;
use App\Support\DisplayFormats;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Tenant::observe(TenantObserver::class);
        WorkItem::observe(WorkItemObserver::class);

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('azure', AzureProvider::class);
        });

        $this->configureFilamentDefaults();
    }

    /**
     * Interface-wide defaults: labels Filament makes up from field names go through the translator,
     * and dates are shown one way (no seconds, month names in the user's language).
     */
    protected function configureFilamentDefaults(): void
    {
        Field::configureUsing(fn (Field $field) => $field->translateLabel());
        Entry::configureUsing(fn (Entry $entry) => $entry->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
        Action::configureUsing(fn (Action $action) => $action->translateLabel());

        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateTimeDisplayFormat(DisplayFormats::DATE_TIME)
            ->defaultDateDisplayFormat(DisplayFormats::DATE));
        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateTimeDisplayFormat(DisplayFormats::DATE_TIME)
            ->defaultDateDisplayFormat(DisplayFormats::DATE));
    }
}
