<?php

namespace App\Filament\Resources\HtDataImports\Pages;

use App\Filament\Resources\HtDataImports\HtDataImportResource;
use App\Models\HtDataImport;
use App\Services\HazecoHtDataImportService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateHtDataImport extends CreateRecord
{
    protected static string $resource = HtDataImportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['imported_by'] = auth()->id();
        $data['file_name'] = basename($data['stored_path']);
        $data['sheet_name'] = HazecoHtDataImportService::SHEET;
        $data['status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var HtDataImport $import */
        $import = $this->record;

        try {
            $result = app(HazecoHtDataImportService::class)->import(
                $import,
                Storage::disk('local')->path($import->stored_path),
            );

            Notification::make()
                ->title('HT feeder data imported')
                ->body("{$result->created_rows} created, {$result->updated_rows} updated, {$result->rejected_rows} rejected.")
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('HT feeder import failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
