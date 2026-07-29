<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers;

use App\Enums\SoftwareLicenseBillingPeriod;
use App\Enums\SoftwareLicenseMetric;
use App\Enums\SoftwareLicenseType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'licenses';

    protected static ?string $title = 'Licenses';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->maxLength(255),
                TextInput::make('product_code')
                    ->label('Product code')
                    ->maxLength(255),
                Select::make('license_type')
                    ->options(collect(SoftwareLicenseType::cases())
                        ->mapWithKeys(fn (SoftwareLicenseType $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all())
                    ->native(false),
                Select::make('metric')
                    ->options(collect(SoftwareLicenseMetric::cases())
                        ->mapWithKeys(fn (SoftwareLicenseMetric $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all())
                    ->native(false),
                TextInput::make('seat_count')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('seats_used')
                    ->numeric()
                    ->minValue(0),
                DatePicker::make('starts_on'),
                DatePicker::make('expires_on'),
                Toggle::make('auto_renew')
                    ->default(false),
                TextInput::make('cost_amount')
                    ->numeric()
                    ->step(0.01),
                TextInput::make('cost_currency')
                    ->maxLength(3)
                    ->default('EUR'),
                Select::make('billing_period')
                    ->options(collect(SoftwareLicenseBillingPeriod::cases())
                        ->mapWithKeys(fn (SoftwareLicenseBillingPeriod $case): array => [
                            $case->value => $case->label(),
                        ])
                        ->all())
                    ->native(false),
                TextInput::make('vendor_contract_reference')
                    ->label('Vendor contract reference')
                    ->maxLength(255),
                TextInput::make('purchase_order_reference')
                    ->label('Purchase order reference')
                    ->maxLength(255),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->placeholder('—'),
                TextColumn::make('seat_count')
                    ->label('Seats')
                    ->placeholder('—'),
                TextColumn::make('expires_on')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('auto_renew')
                    ->boolean(),
            ])
            ->defaultSort('expires_on')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = Filament::getTenant()->getKey();

                        return $data;
                    })
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_license.create') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_license.update') ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_license.delete') ?? false),
            ]);
    }
}
