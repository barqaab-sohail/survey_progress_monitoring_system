<?php

namespace App\Filament\Resources\TransformerKmzImports\Pages;

use App\Filament\Forms\Components\KmzFileUpload;
use App\Filament\Resources\TransformerKmzImports\TransformerKmzImportResource;
use App\Models\Feeder;
use App\Models\TransformerKmzImport;
use App\Services\TransformerKmzImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListTransformerKmzImports extends ListRecords
{
    protected static string $resource = TransformerKmzImportResource::class;

    public static function existingTransformerNotice(mixed $feederId): ?string
    {
        if (! is_numeric($feederId)) {
            return null;
        }
        $feeder = Feeder::withCount('transformers')->find((int) $feederId);
        if (! $feeder) {
            return null;
        }
        if ($feeder->transformers_count > 0) {
            $records = $feeder->transformers_count === 1 ? 'record' : 'records';

            return "Transformers have already been imported or added for this feeder ({$feeder->transformers_count} {$records}). Check the existing reference before importing again: a replacement KMZ can update or remove existing transformer records.";
        }
        $previous = TransformerKmzImport::where('feeder_id', $feeder->id)->where('status', 'completed')->latest('id')->first();
        if ($previous) {
            return "This feeder already has a completed KMZ import ({$previous->transformer_count} transformers counted), but no transformer detail records. Review the previous import's warnings before importing again.";
        }

        return null;
    }

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
                                ->live()
                                ->helperText(fn ($state): string => static::existingTransformerNotice($state) ?? 'Select the feeder before uploading its KMZ file.')
                                ->afterStateUpdated(function ($state): void {
                                    if ($notice = static::existingTransformerNotice($state)) {
                                        Notification::make()->title('Existing transformer data found for this feeder')->body($notice)->warning()->send();
                                    }
                                })
                                ->required(),
                            KmzFileUpload::make('stored_path')
                                ->label('KMZ file')
                                ->disabled(fn (Get $get): bool => ! filled($get('feeder_id')))
                                ->disk('local')
                                ->directory('transformer-kmz-imports')
                                ->storeFileNamesIn('original_name')
                                ->helperText('Google Earth KMZ files only (.kmz), up to 15 MB.')
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
                            if ($result->errors || $result->pending_rows || $result->skipped_rows) {
                                $warnings[] = $import->file_name.': '.($result->created_rows + $result->updated_rows).' records synchronized; '.$result->pending_rows.' need verification; '.$result->skipped_rows.' could not be registered. Open the import details for each warning.';
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
