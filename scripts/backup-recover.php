<?php

use App\Services\Backup\Archive;
use App\Services\Backup\Cipher;

// Standalone offline utility: requires PHP 8.2+, sodium and zip; no Laravel boot or database access.
require_once __DIR__.'/../app/Services/Backup/Failure.php';
require_once __DIR__.'/../app/Services/Backup/Cipher.php';
require_once __DIR__.'/../app/Services/Backup/Archive.php';

umask(0077);

function safeRelative(string $name): void
{
    if ($name === '' || str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, ':') || str_starts_with($name, '/')) {
        throw new RuntimeException('Unsafe recovery path.');
    }
}

try {
    $command = $argv[1] ?? '';
    if ($command === 'keygen' && count($argv) === 3) {
        $dir = $argv[2];
        if (file_exists($dir) || ! mkdir($dir, 0700, true)) {
            throw new RuntimeException('Key directory must be new and private.');
        }
        $pair = sodium_crypto_box_keypair();
        file_put_contents($dir.'/backup-private.key', base64_encode(sodium_crypto_box_secretkey($pair))."\n");
        file_put_contents($dir.'/backup-public.key', base64_encode(sodium_crypto_box_publickey($pair))."\n");
        chmod($dir.'/backup-private.key', 0600);
        echo "Keys created. Keep backup-private.key offline, outside Bluehost and separate from Drive archives.\n";
        echo 'BACKUP_PUBLIC_KEY='.trim(file_get_contents($dir.'/backup-public.key'))."\n";
    } elseif ($command === 'decrypt' && count($argv) === 5) {
        $private = base64_decode(trim(file_get_contents($argv[3])), true);
        if ($private === false) {
            throw new RuntimeException('Invalid private key file.');
        }
        Cipher::decrypt($argv[2], $argv[4], $private);
        sodium_memzero($private);
        echo "Authenticated decryption completed.\n";
    } elseif ($command === 'verify' && count($argv) === 3) {
        $manifest = Archive::verify($argv[2]);
        echo 'Verified '.$manifest['id'].': '.count($manifest['files'])." manifest entries.\n";
    } elseif ($command === 'extract' && count($argv) === 4) {
        $manifest = Archive::verify($argv[2]);
        $destination = $argv[3];
        if (file_exists($destination) || ! mkdir($destination, 0700, true)) {
            throw new RuntimeException('Extraction destination must not already exist.');
        }
        $zip = new ZipArchive;
        $zip->open($argv[2]);
        foreach (array_merge(array_keys($manifest['files']), ['manifest.json']) as $name) {
            safeRelative($name);
            $target = $destination.'/'.$name;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $input = $zip->getStream($name);
            $output = fopen($target, 'xb');
            if (! $input || ! $output || stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Extraction failed.');
            }
            fclose($input);
            fclose($output);
            chmod($target, 0600);
        }
        $zip->close();
        echo "Extracted into a new private directory. No database was contacted.\n";
    } elseif ($command === 'local-originals' && count($argv) === 3) {
        $dir = $argv[2];
        $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['drive_originals'] as $original) {
            $roots = ['local' => 'storage/app/private', 'public' => 'storage/app/public'];
            if (! isset($roots[$original['disk']])) {
                throw new RuntimeException('Map the custom disk manually before recovery.');
            }
            safeRelative($original['path']);
            safeRelative($original['entry']);
            $source = $dir.'/'.$original['entry'];
            $target = $dir.'/application/'.$roots[$original['disk']].'/'.$original['path'];
            $expected = $manifest['files'][$original['entry']];
            if (! hash_equals($expected['sha256'], hash_file('sha256', $source))) {
                throw new RuntimeException('Original checksum mismatch.');
            }
            if (is_file($target)) {
                if (! hash_equals($expected['sha256'], hash_file('sha256', $target))) {
                    throw new RuntimeException('Refusing to replace a different local original.');
                }

                continue;
            }
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            if (! copy($source, $target)) {
                throw new RuntimeException('Could not materialize original.');
            }
            chmod($target, 0600);
        }
        echo "Managed originals copied into the extracted application's local disks. See docs/BACKUPS.md for isolated DB updates.\n";
    } else {
        fwrite(STDERR, "Usage:\n  php scripts/backup-recover.php keygen NEW_OFFLINE_DIRECTORY\n  php scripts/backup-recover.php decrypt ARCHIVE PRIVATE_KEY_FILE NEW_ZIP\n  php scripts/backup-recover.php verify ZIP\n  php scripts/backup-recover.php extract ZIP NEW_DIRECTORY\n  php scripts/backup-recover.php local-originals EXTRACTED_DIRECTORY\n");
        exit(1);
    }
} catch (Throwable $e) {
    // Offline utility emits curated errors only; no config snapshots or key contents.
    fwrite(STDERR, 'Recovery operation failed ('.$e::class."). Check arguments, key, archive integrity and available disk space.\n");
    exit(1);
}
