<?php

namespace Tests\Unit;

use App\Services\Backup\Archive;
use App\Services\Backup\Cipher;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BackupCipherTest extends TestCase
{
    private string $dir;

    private string $pair;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/spms-cipher-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->pair = sodium_crypto_box_keypair();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
    }

    public function test_large_stream_round_trip_preserves_every_byte(): void
    {
        $body = random_bytes(Cipher::CHUNK * 2 + 59);
        file_put_contents($this->dir.'/plain', $body);
        Cipher::encrypt($this->dir.'/plain', $this->dir.'/enc', sodium_crypto_box_publickey($this->pair));
        Cipher::decrypt($this->dir.'/enc', $this->dir.'/out', sodium_crypto_box_secretkey($this->pair));
        $this->assertSame(hash('sha256', $body), hash_file('sha256', $this->dir.'/out'));
        $this->assertStringNotContainsString(substr($body, 0, 100), file_get_contents($this->dir.'/enc'));
    }

    public function test_tampering_truncation_wrong_key_and_trailing_data_are_rejected_without_plaintext_output(): void
    {
        file_put_contents($this->dir.'/plain', 'Sensitive application data');
        Cipher::encrypt($this->dir.'/plain', $this->dir.'/enc', sodium_crypto_box_publickey($this->pair));
        $encrypted = file_get_contents($this->dir.'/enc');
        $tampered = $encrypted;
        $tampered[120] = chr(ord($tampered[120]) ^ 1);
        foreach ([$tampered, substr($encrypted, 0, -4), $encrypted.'extra', $encrypted] as $i => $invalid) {
            file_put_contents($this->dir.'/invalid', $invalid);
            $key = $i === 3 ? sodium_crypto_box_secretkey(sodium_crypto_box_keypair()) : sodium_crypto_box_secretkey($this->pair);
            try {
                Cipher::decrypt($this->dir.'/invalid', $this->dir.'/out', $key);
                $this->fail('Invalid encrypted archive was accepted.');
            } catch (\RuntimeException) {
                $this->assertFileDoesNotExist($this->dir.'/out');
            }
        }
    }

    public function test_restoration_checks_decompressed_bytes_not_only_zip_headers(): void
    {
        $path = $this->dir.'/backup.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'CREATE TABLE example (id INT);');
        $zip->addFromString('manifest.json', json_encode(['format' => 1, 'files' => ['database.sql' => [
            'bytes' => 30, 'sha256' => hash('sha256', 'a different database dump'),
        ]]]));
        $zip->close();
        $this->expectException(\RuntimeException::class);
        Archive::verify($path);
    }

    public function test_standalone_recovery_cli_generates_keys_verifies_extracts_and_materializes_originals(): void
    {
        $script = dirname(__DIR__, 2).'/scripts/backup-recover.php';
        $execute = function (array $arguments) use ($script) {
            $process = new Process(array_merge([PHP_BINARY, $script], $arguments));
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'Offline recovery command failed.');
        };
        $execute(['keygen', $this->dir.'/keys']);
        $entries = ['database.sql' => 'SELECT 42;', 'drive-originals/9' => 'recovered PDF original'];
        $manifest = ['format' => 1, 'id' => 'offline-cli-fixture', 'files' => [],
            'drive_originals' => [['id' => 9, 'entry' => 'drive-originals/9', 'disk' => 'local', 'path' => 'evidence/original.pdf']]];
        $zip = new \ZipArchive;
        $zip->open($this->dir.'/plain.zip', \ZipArchive::CREATE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
            $manifest['files'][$name] = ['bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
        }
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        Cipher::encrypt($this->dir.'/plain.zip', $this->dir.'/cloud.enc', Cipher::publicKey(trim(file_get_contents($this->dir.'/keys/backup-public.key'))));
        $execute(['decrypt', $this->dir.'/cloud.enc', $this->dir.'/keys/backup-private.key', $this->dir.'/recovered.zip']);
        $execute(['verify', $this->dir.'/recovered.zip']);
        $execute(['extract', $this->dir.'/recovered.zip', $this->dir.'/restore']);
        $execute(['local-originals', $this->dir.'/restore']);
        $this->assertSame('recovered PDF original', file_get_contents($this->dir.'/restore/application/storage/app/private/evidence/original.pdf'));
        $this->assertSame('SELECT 42;', file_get_contents($this->dir.'/restore/database.sql'));
    }
}
