<?php

namespace App\Services\Backup;

use Illuminate\Filesystem\Filesystem;

class Workspace
{
    public readonly string $path;

    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
        $path = (string) config('backups.path');
        if (! $path || is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true))) {
            throw new Failure('Cannot create private BACKUP_PATH.');
        }
        $this->path = realpath($path);
        // No SQL export or ZIP may be served by Laravel.
        foreach (array_merge([base_path(), public_path(), config('filesystems.disks.local.root'), config('filesystems.disks.public.root')], config('backups.web_roots', [])) as $root) {
            $root = realpath($root) ?: $root;
            if ($this->within($this->path, $root) || $this->within($root, $this->path)) {
                throw new Failure('BACKUP_PATH must be outside the project and every served storage root.');
            }
        }
        if (preg_match('~/(public_html|htdocs|wwwroot|www)(/|$)~i', str_replace('\\', '/', $this->path))) {
            throw new Failure('BACKUP_PATH must be outside all web document roots, including public_html/htdocs.');
        }
        if (! chmod($this->path, 0700) || ! is_writable($this->path)) {
            throw new Failure('BACKUP_PATH must be private and writable.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->path) & 0077)) {
            throw new Failure('BACKUP_PATH permissions must be 0700.');
        }
        // Defense in depth if an Apache document root is accidentally widened later.
        if (is_link($this->path.'/.htaccess')) {
            throw new Failure('Symlinks are forbidden in BACKUP_PATH.');
        }
        file_put_contents($this->path.'/.htaccess', "Require all denied\nOptions -Indexes\n");
        chmod($this->path.'/.htaccess', 0600);
    }

    private function within(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }

    public function lock()
    {
        if (is_link($this->path.'/runner.lock')) {
            throw new Failure('Invalid backup lock path.');
        }
        $file = fopen($this->path.'/runner.lock', 'c');
        if (! $file || ! flock($file, LOCK_EX | LOCK_NB)) {
            if ($file) {
                fclose($file);
            }

            return false;
        }
        chmod($this->path.'/runner.lock', 0600);

        return $file; // Kernel releases flock even when the process is killed; no stale TTL lock.
    }

    public function read(string $name): array
    {
        $path = $this->receiptPath($name);

        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function save(string $name, array $value): void
    {
        $path = $this->receiptPath($name);
        $temp = $path.'.tmp';
        if (is_link($temp)) {
            throw new Failure('Invalid backup receipt path.');
        }
        if (file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false
            || ! chmod($temp, 0600) || ! rename($temp, $path)) {
            throw new Failure('Backup receipt could not be persisted.');
        }
    }

    private function receiptPath(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $name) || is_link($this->path.'/'.$name.'.json')) {
            throw new Failure('Invalid backup receipt path.');
        }

        return $this->path.'/'.$name.'.json';
    }

    public function tick(int $additional = 0): void
    {
        if (microtime(true) - $this->started > config('backups.timeout')) {
            throw new Failure('Backup time budget exceeded.');
        }
        if (disk_free_space($this->path) < config('backups.min_free_bytes') + $additional) {
            throw new Failure('Insufficient free backup space.');
        }
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                throw new Failure('Symlinks are forbidden in BACKUP_PATH.');
            }
            $bytes += $file->getSize();
        }
        if ($bytes + $additional > config('backups.max_workspace_bytes')) {
            throw new Failure('Backup workspace size limit exceeded.');
        }
    }

    public function remainingSeconds(): int
    {
        return max(1, (int) (config('backups.timeout') - (microtime(true) - $this->started)));
    }

    public function createRun(): string
    {
        $directory = $this->path.'/run-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new Failure('Cannot create a private backup run directory.');
        }

        return $directory;
    }

    public function cleanRun(string $directory): void
    {
        // Check the exact resolved child before recursive cleanup; never touch older backups.
        $resolved = realpath($directory);
        if (is_link($directory) || ! $resolved || dirname($resolved) !== $this->path
            || ! preg_match('/^run-[a-f0-9]{24}$/D', basename($resolved))) {
            throw new Failure('Invalid backup cleanup directory.');
        }
        (new Filesystem)->deleteDirectory($resolved);
        if (is_dir($resolved)) {
            throw new Failure('Uploaded backup is verified, but private local cleanup failed.');
        }
    }
}
