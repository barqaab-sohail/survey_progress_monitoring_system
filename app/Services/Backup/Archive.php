<?php

namespace App\Services\Backup;

use App\Models\DriveStoredFile;
use App\Services\DriveStorage\FileStorage;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class Archive
{
    public function create(string $id, Workspace $workspace): string
    {
        $directory = $workspace->path.'/work';
        if (! mkdir($directory, 0700)) {
            throw new Failure('Cannot create backup work directory.');
        }
        $snapshotAt = now()->toIso8601String();
        // Bracket the SQL snapshot with stable file inventories. Concurrent changes fail safely;
        // uploads need not be disabled, and a disappearing original can never silently be omitted.
        $sources = $this->sourceInventory($workspace);
        $managed = Schema::hasTable('drive_stored_files') ? DriveStoredFile::orderBy('id')->get() : collect();
        $managedFingerprint = $this->managedFingerprint($managed);
        $workspace->tick(array_sum(array_column($sources, 'bytes')) + (int) $managed->sum('bytes') * 2);
        $database = app(DatabaseExporter::class)->export($directory, $workspace);
        $manifest = ['format' => 1, 'id' => $id, 'created_at' => $snapshotAt,
            'laravel' => app()->version(), 'php' => PHP_VERSION, 'database' => $database,
            'drive_originals' => [], 'files' => []];
        $archive = $directory.'/backup.zip';
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new Failure('Cannot create backup ZIP.');
        }
        $add = function (string $path, string $name, ?array $expected = null) use ($zip, &$manifest, $workspace) {
            $workspace->tick();
            if (! is_readable($path) || ! is_file($path)) {
                throw new Failure('A required backup file is unreadable.');
            }
            $size = filesize($path);
            if (! $zip->addFile($path, $name)) {
                throw new Failure('Backup ZIP entry could not be added.');
            }
            $manifest['files'][$name] = ['bytes' => $size, 'sha256' => hash_file('sha256', $path)];
            if ($expected && $manifest['files'][$name] !== ['bytes' => $expected['bytes'], 'sha256' => $expected['sha256']]) {
                throw new Failure('A source file changed during capture; retry in a quiet window.');
            }
        };
        try {
            $add($directory.'/database.sql', 'database.sql');
            foreach ($sources as $path => $source) {
                $add($path, 'application/'.$source['name'], $source);
            }
            if (! config('app.key')) {
                throw new Failure('The original APP_KEY is required for recoverable backups.');
            }
            // Covers settings injected by cPanel/server environment, not only the .env file.
            $effective = $directory.'/effective-config.json';
            file_put_contents($effective, json_encode(config()->all(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            chmod($effective, 0600);
            $add($effective, 'recovery/effective-config.json');
            if ($managed->isNotEmpty()) {
                foreach ($managed as $file) {
                    $workspace->tick($file->bytes * 2);
                    // Materialize every managed original, including files removed from Bluehost after upload.
                    $copy = $directory.'/original-'.$file->id;
                    $expectedHash = $file->sha256;
                    app(Drive::class)->retry(fn () => app(FileStorage::class)->withPath($file, $file->local_disk, $file->local_path, function ($source) use ($copy) {
                        if (! copy($source, $copy)) {
                            throw new Failure('Could not stage a managed Drive original.');
                        }
                        chmod($copy, 0600);
                    }), fn () => $workspace->tick());
                    if (! hash_equals($expectedHash, hash_file('sha256', $copy))) {
                        throw new Failure('A managed original failed SHA-256 verification.');
                    }
                    $name = 'drive-originals/'.$file->id;
                    $add($copy, $name);
                    $manifest['drive_originals'][] = ['id' => $file->id, 'entry' => $name, 'disk' => $file->local_disk,
                        'path' => $file->local_path, 'drive_file_id' => $file->drive_file_id];
                }
            }
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
                throw new Failure('Backup manifest could not be written.');
            }
            // Compression happens at close; reserve room for incompressible content and ZIP overhead.
            $workspace->tick(array_sum(array_column($manifest['files'], 'bytes')) + 1048576);
            if (! $zip->close()) {
                throw new Failure('Backup ZIP could not be finalized; check disk quota.');
            }
            $zip = null;
            chmod($archive, 0600);
            self::verify($archive, fn () => $workspace->tick());
            if ($sources !== $this->sourceInventory($workspace)
                || $managedFingerprint !== $this->managedFingerprint(Schema::hasTable('drive_stored_files') ? DriveStoredFile::orderBy('id')->get() : collect())) {
                throw new Failure('Uploads changed during capture. Retry during a quiet window.');
            }
        } finally {
            if ($zip) {
                $zip->close();
            }
        }

        return $archive;
    }

    private function sourceInventory(Workspace $workspace): array
    {
        $files = [];
        foreach ($this->sourceFiles() as $path => $name) {
            $workspace->tick();
            clearstatcache(true, $path);
            if (! is_readable($path)) {
                throw new Failure('A persistent source is unreadable.');
            }
            $files[$path] = ['name' => $name, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }
        ksort($files);

        return $files;
    }

    private function managedFingerprint($files): string
    {
        return hash('sha256', json_encode($files->map(fn ($file) => $file->only([
            'id', 'sha256', 'bytes', 'local_disk', 'local_path', 'drive_file_id', 'account_id',
        ]))->all(), JSON_THROW_ON_ERROR));
    }

    /** Explicit persistent roots, hidden files included; no following symlinks. */
    private function sourceFiles(): \Generator
    {
        $seen = [];
        $roots = [];
        foreach (config('backups.include') as $pattern) {
            foreach (glob(base_path($pattern), GLOB_NOSORT) ?: [] as $path) {
                $roots[] = $path;
            }
        }
        foreach (config('backups.root_files') as $name) {
            if (is_file(base_path($name))) {
                $roots[] = base_path($name);
            }
        }
        foreach ($roots as $root) {
            $entries = is_dir($root) ? $this->walk($root) : [$root];
            foreach ($entries as $path) {
                if (! str_starts_with($path, base_path().DIRECTORY_SEPARATOR) || str_contains(str_replace('\\', '/', $path), '/../')) {
                    throw new Failure('Backup include paths must stay within the project.');
                }
                $name = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
                if (isset($seen[$name]) || $this->excluded($name)) {
                    continue;
                }
                if (is_link($path)) {
                    throw new Failure('A persistent backup path contains a symlink; configure a real upload path.');
                }
                $seen[$name] = true;
                yield $path => $name;
            }
        }
    }

    private function walk(string $directory): \Generator
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            $path = $file->getPathname();
            $name = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            if ($this->excluded($name)) {
                continue;
            }
            if ($file->isLink()) {
                throw new Failure('A persistent backup directory contains a symlink.');
            }
            if ($file->isDir()) {
                yield from $this->walk($path);
            } elseif ($file->isFile()) {
                yield $path;
            }
        }
    }

    private function excluded(string $name): bool
    {
        foreach (config('backups.exclude') as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix.'/')) {
                return true;
            }
        }
        if (preg_match('~(^|/)(\.git|node_modules|vendor|\.dart_tool|\.gradle|build|logs|cache|tmp|temp|backups?)(/|$)~i', $name)
            && ! str_starts_with($name, 'public/') && ! str_starts_with($name, 'storage/app/')) {
            return true;
        }
        if (preg_match('~(^|/)(backups?|\.backups)(/|$)|\.(backup\.enc|log|tmp|swp)$|(^|/)\~\$~i', $name)) {
            return true;
        }
        if (str_starts_with($name, 'database/') && preg_match('/\.(sqlite|sqlite3)(-wal|-shm)?$/', $name)) {
            return true;
        }

        return false;
    }

    /** Verify actual decompressed bytes against the manifest; catches source changes during ZIP creation. */
    public static function verify(string $path, ?callable $tick = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new Failure('Backup ZIP integrity check failed.');
        }
        try {
            $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 1 || empty($manifest['files']) || $zip->numFiles !== count($manifest['files']) + 1) {
                throw new Failure('Incomplete backup manifest.');
            }
            foreach ($manifest['files'] as $name => $expected) {
                if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, ':')) {
                    throw new Failure('Unsafe archive path.');
                }
                $stream = $zip->getStream($name);
                if (! $stream) {
                    throw new Failure('A backup manifest entry is missing.');
                }
                try {
                    $hash = hash_init('sha256');
                    $bytes = 0;
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1048576);
                        if ($chunk === false) {
                            throw new Failure('Backup ZIP read failed.');
                        }
                        $bytes += strlen($chunk);
                        hash_update($hash, $chunk);
                        $tick && $tick();
                    }
                    if ($bytes !== $expected['bytes'] || ! hash_equals($expected['sha256'], hash_final($hash))) {
                        throw new Failure('Backup content changed or failed its manifest checksum.');
                    }
                } finally {
                    fclose($stream);
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }
}
