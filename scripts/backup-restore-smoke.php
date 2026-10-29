<?php

use App\Services\Backup\Archive;
use App\Services\Backup\Cipher;
use App\Services\Backup\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// Explicitly opt-in, disposable LOCAL MySQL restore rehearsal. Never touches an existing schema.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

umask(0077);
$db = null;
$created = [];
$workspace = null;
$lock = null;
$stage = 'local safety checks';
$originalDefault = config('database.default');
try {
    $config = DB::connection('mysql')->getConfig();
    if (($argv[1] ?? '') !== '--run' || ! app()->environment(['local', 'testing'])
        || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Requires --run, local/testing environment and loopback MySQL.');
    }
    $dumpBinary = $argv[2] ?? 'mysqldump';
    $mysqlBinary = $argv[3] ?? 'mysql';
    $stem = 'spms_backup_test_'.bin2hex(random_bytes(6));
    $source = $stem.'_source';
    $target = $stem.'_restore';
    $db = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4', $config['username'], $config['password'], $config['options'] ?? []);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stage = 'create disposable schemas';
    foreach ([$source, $target] as $name) {
        $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');
        $created[] = $name;
    }
    config(['database.connections.backup_smoke' => array_merge($config, ['url' => null, 'database' => $source]),
        'backups.path' => sys_get_temp_dir().'/spms-backup-smoke-'.bin2hex(random_bytes(8)),
        'backups.mysqldump' => $dumpBinary, 'backups.min_free_bytes' => 0,
        'backups.include' => ['tests/Fixtures/recovery'], 'backups.root_files' => ['composer.lock']]);
    DB::setDefaultConnection('backup_smoke');
    DB::statement('CREATE TABLE items (id INT PRIMARY KEY, label VARCHAR(100), secret TEXT, raw_bytes LONGBLOB) ENGINE=InnoDB');
    DB::statement('CREATE TABLE children (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES items(id)) ENGINE=InnoDB');
    $secret = Crypt::encryptString('recoverable encrypted application value');
    DB::insert('INSERT INTO items VALUES (?, ?, ?, ?)', [7, 'Karachi survey — بازیابی', $secret, "\x00\x01\xff"]);
    DB::insert('INSERT INTO children VALUES (1, 7)');
    DB::statement('CREATE VIEW item_labels AS SELECT id, label FROM items');
    DB::unprepared('CREATE TRIGGER preserve_label BEFORE UPDATE ON items FOR EACH ROW SET NEW.label = OLD.label');
    $workspace = new Workspace;
    $lock = $workspace->lock();
    $stage = 'snapshot and encrypt';
    $archive = app(Archive::class)->create('isolated-mysql-rehearsal', $workspace);
    $pair = sodium_crypto_box_keypair();
    Cipher::encrypt($archive, $workspace->path.'/downloaded.backup.enc', sodium_crypto_box_publickey($pair));
    $workspace->cleanPlaintext();
    $stage = 'offline authenticated recovery';
    Cipher::decrypt($workspace->path.'/downloaded.backup.enc', $workspace->path.'/restored.zip', sodium_crypto_box_secretkey($pair));
    $manifest = Archive::verify($workspace->path.'/restored.zip');
    $zip = new ZipArchive;
    $zip->open($workspace->path.'/restored.zip');
    $settings = json_decode($zip->getFromName('recovery/effective-config.json'), true, 512, JSON_THROW_ON_ERROR);
    if (! hash_equals(config('app.key'), $settings['app']['key'])) {
        throw new RuntimeException('Application key recovery failed.');
    }
    if (! hash_equals(hash_file('sha256', base_path('composer.lock')), hash('sha256', $zip->getFromName('application/composer.lock')))) {
        throw new RuntimeException('Code recovery failed.');
    }
    if (! str_contains($zip->getFromName('application/tests/Fixtures/recovery/uploads/evidence.txt'), 'survey evidence')) {
        throw new RuntimeException('Upload recovery failed.');
    }
    $stage = 'import into new isolated database';
    $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
    $defaults = $workspace->path.'/restore.cnf';
    file_put_contents($defaults, "[client]\nuser=".$quote($config['username'])."\npassword=".$quote($config['password'])."\nhost=".$quote($config['host'])."\nport=".(int) $config['port']."\n");
    $process = new Process([$mysqlBinary, '--defaults-extra-file='.$defaults, '--default-character-set=utf8mb4', $target]);
    $process->setInput($zip->getStream('database.sql'))->setTimeout(60)->disableOutput()->run();
    unlink($defaults);
    $zip->close();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Isolated SQL import failed.');
    }
    $row = $db->query('SELECT * FROM `'.$target.'`.items')->fetch(PDO::FETCH_ASSOC);
    if ($row['label'] !== 'Karachi survey — بازیابی' || $row['raw_bytes'] !== "\x00\x01\xff"
        || Crypt::decryptString($row['secret']) !== 'recoverable encrypted application value') {
        throw new RuntimeException('Restored row mismatch.');
    }
    if ((int) $db->query('SELECT COUNT(*) FROM `'.$target.'`.children')->fetchColumn() !== 1) {
        throw new RuntimeException('Foreign-key data missing.');
    }
    if ((int) $db->query('SELECT COUNT(*) FROM `'.$target.'`.item_labels')->fetchColumn() !== 1) {
        throw new RuntimeException('View missing.');
    }
    $db->exec('UPDATE `'.$target.'`.items SET label = \'different\' WHERE id = 7');
    if ($db->query('SELECT label FROM `'.$target.'`.items WHERE id = 7')->fetchColumn() !== $row['label']) {
        throw new RuntimeException('Trigger missing.');
    }
    echo 'PASS: actual MySQL dump -> encrypted archive -> authenticated decryption -> verified code/uploads/APP_KEY -> isolated MySQL restore, Unicode/binary/encrypted data, foreign keys, view and trigger. '.count($manifest['files'])." manifest entries.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore rehearsal failed at '.$stage.' ('.$e::class."). Existing databases were not targeted.\n");
    $failed = true;
} finally {
    DB::disconnect('backup_smoke');
    DB::setDefaultConnection($originalDefault);
    foreach ($created as $name) {
        if (preg_match('/^spms_backup_test_[a-f0-9]{12}_(source|restore)$/D', $name)) {
            $db->exec('DROP DATABASE `'.$name.'`');
        }
    }
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if ($workspace) {
        $resolved = realpath($workspace->path);
        $allowed = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/spms-backup-smoke-';
        if ($resolved && ! is_link($resolved) && str_starts_with(str_replace('\\', '/', $resolved), $allowed)) {
            (new Filesystem)->deleteDirectory($resolved);
        }
    }
}
exit(isset($failed) ? 1 : 0);
