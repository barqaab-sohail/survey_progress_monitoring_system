<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class DatabaseExporter
{
    public function export(string $directory, Workspace $workspace): array
    {
        $db = DB::connection();
        $config = $db->getConfig(); // Includes resolved DB_URL, not just individual DB_* variables.
        if (! in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            throw new Failure('Production backups require MySQL/MariaDB.');
        }
        $tables = $db->select('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$db->getDatabaseName(), 'BASE TABLE']);
        foreach ($tables as $table) {
            if (strtoupper($table->ENGINE ?? '') !== 'INNODB') {
                throw new Failure('Consistent online export requires every table to use InnoDB.');
            }
        }
        $estimate = $db->selectOne('SELECT COALESCE(SUM(DATA_LENGTH), 0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$db->getDatabaseName()]);
        $workspace->tick((int) $estimate->bytes * 3);
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
        $options = "[client]\nuser=".$quote($config['username'])."\npassword=".$quote($config['password'])."\nhost=".$quote($config['host'])."\nport=".(int) $config['port']."\n";
        if (! empty($config['unix_socket'])) {
            $options .= 'socket='.$quote($config['unix_socket'])."\n";
        }
        if ($ca = $config['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null) {
            $options .= 'ssl-ca='.$quote($ca)."\n";
        }
        $credentials = $directory.'/mysql.cnf';
        file_put_contents($credentials, $options);
        chmod($credentials, 0600);
        $sql = $directory.'/database.sql';
        $args = [config('backups.mysqldump'), '--defaults-extra-file='.$credentials,
            '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob',
            '--default-character-set=utf8mb4', '--routines', '--events', '--triggers', '--result-file='.$sql, $db->getDatabaseName()];
        // MySQL-only options must not be sent to MariaDB's dump binary.
        $help = new Process([config('backups.mysqldump'), '--no-defaults', '--help'], timeout: 15);
        $help->run();
        $supported = $help->getOutput();
        foreach (['set-gtid-purged' => 'OFF', 'column-statistics' => '0'] as $option => $value) {
            if (str_contains($supported, '--'.$option)) {
                array_splice($args, -1, 0, ['--'.$option.'='.$value]);
            }
        }
        $process = new Process($args, null, ['MYSQL_PWD' => false], null, $workspace->remainingSeconds());
        $process->disableOutput(); // SQL goes directly to file; credentials/errors never enter logs.
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $workspace->tick();
                usleep(200000);
            }
            if (! $process->isSuccessful() || ! is_file($sql) || filesize($sql) === 0) {
                throw new Failure('MySQL export failed. Check dump binary, connection and routines/events privileges.');
            }
            chmod($sql, 0600);
        } catch (\Throwable) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            throw new Failure('MySQL export failed or exceeded the resource budget. Check host permissions and limits.');
        } finally {
            unlink($credentials);
        }

        return ['driver' => $config['driver'], 'tables' => count($tables), 'consistent_snapshot' => 'InnoDB single transaction', 'file' => 'database.sql'];
    }
}
