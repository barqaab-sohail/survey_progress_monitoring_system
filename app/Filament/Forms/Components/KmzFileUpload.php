<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class KmzFileUpload extends FileUpload
{
    private const KMZ_MIME = 'application/vnd.google-earth.kmz';

    protected function setUp(): void
    {
        parent::setUp();

        // FilePond accepts KMZ only. PHP may identify a genuine KMZ as ZIP,
        // so server validation checks the original extension separately.
        $this->acceptedFileTypes = [self::KMZ_MIME];
        $this->mimeTypeMap(['kmz' => self::KMZ_MIME]);
        $this->rules([
            'extensions:kmz',
            'mimetypes:application/vnd.google-earth.kmz,application/zip,application/x-zip-compressed,application/octet-stream',
        ]);
        $this->validationMessages([
            'extensions' => 'Upload a .kmz file. ZIP and other file extensions are not accepted.',
            'mimetypes' => 'Upload a valid Google Earth KMZ archive.',
        ]);
        $this->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::ulid().'.kmz');
    }

    public function getUploadedFile(string $file, string|array|null $storedFileNames): ?array
    {
        $info = parent::getUploadedFile($file, $storedFileNames);
        if ($info !== null && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'kmz') {
            $info['type'] = self::KMZ_MIME;
        }

        return $info;
    }
}
