<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\RelationManagers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAdminAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TenantAdminsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenantAdminUsers';

    protected static ?string $title = 'Tenant administrators';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (User $record): string => $this->sourceLabel($record)),
            ])
            ->headerActions([
                Action::make('addTenantAdministrator')
                    ->label('Add tenant administrator')
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->options(fn (): array => $this->candidateOptions())
                            ->searchable()
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->ownerRecord;

                        $user = User::query()->findOrFail($data['user_id']);

                        app(TenantAdminAssignmentService::class)->assign($tenant, $user);

                        Notification::make()
                            ->title(__('Tenant administrator assigned'))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, string>
     */
    private function candidateOptions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->ownerRecord;

        return app(TenantAdminAssignmentService::class)
            ->eligibleCandidates($tenant)
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => "{$user->name} ({$user->email})",
            ])
            ->all();
    }

    private function sourceLabel(User $user): string
    {
        if ($user->is_admin) {
            return 'Global admin';
        }

        return 'Tenant agent';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
