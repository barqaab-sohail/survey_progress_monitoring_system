<?php

namespace App\Services\Backup;

use ZipArchive;

class Archive
{
    public function create(string $name, Workspace $workspace, string $directory): string
    {
        app(DatabaseExporter::class)->export($directory, $workspace);
        $sql = $directory.'/database.sql';
        if (! is_file($sql) || filesize($sql) === 0) {
            throw new Failure('The database export is empty or missing.');
        }
        $path = $directory.'/'.$name;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new Failure('Cannot create backup ZIP.');
        }
        $hashes = [];
        try {
            $files = [$sql => 'database.sql'];
            foreach ($this->sourceFiles() as $file => $relative) {
                $files[$file] = 'application/'.$relative;
            }
            foreach ($files as $file => $entry) {
                $workspace->tick();
                if (! is_readable($file)) {
                    throw new Failure('An application source file is unreadable.');
                }
                $hashes[$entry] = hash_file('sha256', $file);
                if (! $zip->addFile($file, $entry)) {
                    throw new Failure('Could not add a file to the backup ZIP.');
                }
            }
            $workspace->tick(array_sum(array_map('filesize', array_keys($files))) + 1048576);
            if (! $zip->close()) {
                throw new Failure('Could not finish the backup ZIP; check hosting quota.');
            }
            $zip = null;
            chmod($path, 0600);
            // Standard ZIP, no encrypted payload/config snapshot or special recovery format.
            $check = new ZipArchive;
            if ($check->open($path, ZipArchive::CHECKCONS) !== true) {
                throw new Failure('Backup ZIP integrity check failed.');
            }
            try {
                foreach ($hashes as $entry => $expected) {
                    $workspace->tick();
                    $stream = $check->getStream($entry);
                    if (! $stream) {
                        throw new Failure('A ZIP entry is missing.');
                    }
                    try {
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        if (! hash_equals($expected, hash_final($hash))) {
                            throw new Failure('A source changed during ZIP creation. Retry outside deployment hours.');
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            } finally {
                $check->close();
            }
        } finally {
            if ($zip) {
                $zip->close();
            }
        }

        return $path;
    }

    private function sourceFiles(): \Generator
    {
        $seen = [];
        foreach (array_merge(config('backups.include'), config('backups.root_files')) as $relative) {
            if (preg_match('~(^|/)\.\.(/|$)~', str_replace('\\', '/', $relative)) || str_starts_with($relative, '/') || str_contains($relative, ':')) {
                throw new Failure('Backup source roots must stay within the project.');
            }
            $root = base_path($relative);
            if (! file_exists($root) || $this->excluded($relative)) {
                continue;
            }
            if (is_link($root)) {
                throw new Failure('Source symlinks are not followed by backups.');
            }
            foreach (is_dir($root) ? $this->walk($root) : [$root] as $file) {
                $name = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
                if (isset($seen[$name]) || $this->excluded($name)) {
                    continue;
                }
                if (is_link($file)) {
                    throw new Failure('Source symlinks are not followed by backups.');
                }
                $seen[$name] = true;
                yield $file => $name;
            }
        }
    }

    private function walk(string $directory): \Generator
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            if ($this->excluded($name)) {
                continue;
            }
            if ($file->isLink()) {
                throw new Failure('Source symlinks are not followed by backups.');
            }
            if ($file->isDir()) {
                yield from $this->walk($file->getPathname());
            } elseif ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    private function excluded(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        foreach (array_merge(config('backups.exclude'), ['storage']) as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix.'/')) {
                return true;
            }
        }
        $base = basename($name);
        // Public vendor/build are deployed application assets (Filament/PDF.js), not Composer dependencies.
        if (! str_starts_with($name, 'public/') && preg_match('~(^|/)(vendor|build)(/|$)~i', $name)) {
            return true;
        }
        if (preg_match('~(^|/)(\.git|node_modules|\.dart_tool|\.gradle|\.idea|storage|logs?|caches?|tmp|temp|backups?|secrets?)(/|$)~i', $name)) {
            return true;
        }
        if (preg_match('/^(\.env($|\.)|auth\.json$|\.npmrc$|\.netrc$|\.my\.cnf$|id_rsa|id_ed25519|key\.properties$)/i', $base)) {
            return true;
        }
        if (preg_match('/(credential|secret|service[_-]?account|firebase-adminsdk)/i', $base)) {
            return true;
        }
        if (preg_match('/\.(key|pem|p12|pfx|jks|keystore|cnf|log|tmp|swp|zip|tar|tgz|7z|rar|enc|bak|old|sqlite|sqlite3)(-wal|-shm)?$/i', $base)) {
            return true;
        }
        if (str_ends_with(strtolower($base), '.sql') && ! str_starts_with($name, 'database/schema/')) {
            return true;
        }
        if (str_starts_with($base, '~$') || str_ends_with($base, '~')) {
            return true;
        }

        return false;
    }

    /** Legacy encrypted-archive reader only; new ZIP backups do not require this manifest/tool. */
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
