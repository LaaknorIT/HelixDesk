<?php

namespace App\Filament\Agent\Resources\People\Pages;

use App\Filament\Agent\Resources\People\PeopleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePeople extends CreateRecord
{
    protected static string $resource = PeopleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['password'])) {
            throw ValidationException::withMessages([
                'password' => __('A password is required for new accounts.'),
            ]);
        }

        $data['tenant_id'] = Filament::getTenant()->getKey();
        $data['is_admin'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        if ($user->type !== 'agent') {
            return;
        }

        setPermissionsTeamId((int) $user->tenant_id);

        try {
            $user->syncRoles(['Agent']);
        } finally {
            setPermissionsTeamId(null);
        }
    }
}
