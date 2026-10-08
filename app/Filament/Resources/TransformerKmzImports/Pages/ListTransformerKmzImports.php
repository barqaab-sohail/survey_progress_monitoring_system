<?php

namespace App\Filament\Resources\TransformerKmzImports\Pages;

use App\Filament\Resources\TransformerKmzImports\TransformerKmzImportResource;
use App\Models\Feeder;
use App\Models\TransformerKmzImport;
use App\Services\TransformerKmzImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListTransformerKmzImports extends ListRecords
{
    protected static string $resource = TransformerKmzImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkImport')
                ->label('Bulk import KMZ files')
                ->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn (): bool => auth()->user()?->can('Create:TransformerKmzImport') ?? false)
                ->modalHeading('Bulk transformer KMZ import')
                ->modalDescription('Choose the existing Excel feeder that each KMZ belongs to. The KMZ source feeder is retained separately for audit; no automatic fuzzy matching is used.')
                ->modalWidth('5xl')
                ->schema([
                    Repeater::make('imports')
                        ->label('Feeder KMZ files')
                        ->schema([
                            Select::make('feeder_id')
                                ->label('Link to existing feeder')
                                ->options(fn (): array => Feeder::active()
                                    ->orderBy('feeder_code')
                                    ->get()
                                    ->mapWithKeys(fn (Feeder $feeder): array => [$feeder->id => $feeder->feeder_code.' — '.$feeder->feeder_name])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                            FileUpload::make('stored_path')
                                ->label('KMZ file')
                                ->disk('local')
                                ->directory('transformer-kmz-imports')
                                ->storeFileNamesIn('original_name')
                                ->acceptedFileTypes([
                                    'application/vnd.google-earth.kmz',
                                    'application/zip',
                                    'application/octet-stream',
                                ])
                                ->maxSize(15360)
                                ->required(),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->maxItems(30)
                        ->defaultItems(1)
                        ->addActionLabel('Add another feeder KMZ')
                        ->reorderable(false),
                ])
                ->action(function (array $data): void {
                    $rows = $data['imports'] ?? [];
                    $feederIds = collect($rows)->pluck('feeder_id')->map(fn ($id): int => (int) $id);
                    if ($feederIds->duplicates()->isNotEmpty()) {
                        Notification::make()->title('Each feeder may appear only once in a batch.')->danger()->send();

                        return;
                    }

                    $completed = 0;
                    $failed = [];
                    $transformers = 0;
                    $warnings = [];
                    $synchronized = 0;
                    foreach ($rows as $row) {
                        $path = (string) ($row['stored_path'] ?? '');
                        $import = TransformerKmzImport::create([
                            'feeder_id' => (int) $row['feeder_id'],
                            'imported_by' => auth()->id(),
                            'file_name' => (string) ($row['original_name'] ?? basename($path)),
                            'stored_path' => $path,
                            'status' => 'pending',
                        ]);

                        try {
                            $result = app(TransformerKmzImportService::class)->import($import, Storage::disk('local')->path($path));
                            $completed++;
                            $transformers += $result->transformer_count;
                            $synchronized += $result->created_rows + $result->updated_rows;
                            if ($result->errors) {
                                $warnings[] = $import->file_name.': Transformer count saved; details could not be imported.';
                            }
                        } catch (Throwable $exception) {
                            $failed[] = $import->file_name.': '.$exception->getMessage();
                        }
                    }

                    $notification = Notification::make()
                        ->title("{$completed} KMZ file(s) imported; {$transformers} transformers counted; {$synchronized} records synchronized")
                        ->body(($failed === [] && $warnings === []) ? 'All files passed validation.' : implode("\n", array_slice(array_merge($failed, $warnings), 0, 5)));
                    if ($failed !== []) {
                        $notification->danger()->persistent()->send();
                    } elseif ($warnings !== []) {
                        $notification->warning()->persistent()->send();
                    } else {
                        $notification->success()->send();
                    }
                }),
        ];
    }
}
