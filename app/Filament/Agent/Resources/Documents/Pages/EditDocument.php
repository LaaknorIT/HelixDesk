<?php

namespace App\Filament\Agent\Resources\Documents\Pages;

use App\Filament\Agent\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Document $record */
        $record = $this->getRecord();

        $slugInput = $data['slug'] ?? '';
        if ($slugInput === '') {
            $data['slug'] = Document::uniqueSlugForTenant((int) $record->tenant_id, (string) $data['title'], (int) $record->getKey());

            return $data;
        }

        if ($slugInput !== $record->slug) {
            $data['slug'] = Document::uniqueSlugForTenant((int) $record->tenant_id, (string) $slugInput, (int) $record->getKey());
        }

        return $data;
    }
}
