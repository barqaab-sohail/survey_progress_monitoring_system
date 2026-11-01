<?php

namespace Tests\Unit;

use App\Services\Mdb\AccessPowerShell;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccessPowerShellEnvironmentTest extends TestCase
{
    public function test_core_windows_environment_is_supplied_even_when_php_host_has_no_environment_values(): void
    {
        $environment = AccessPowerShell::processEnvironment(
            'C:\\Windows\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe',
            null,
            null,
            null,
            'C:\\ExportStage',
        );

        $this->assertSame('C:\\Windows', $environment['SystemRoot']);
        $this->assertSame('C:\\Windows', $environment['WINDIR']);
        $this->assertSame('C:\\Windows\\System32\\cmd.exe', $environment['ComSpec']);
        $this->assertSame('C:\\ExportStage', $environment['TEMP']);
        $this->assertSame('C:\\ExportStage', $environment['TMP']);
    }

    public function test_existing_windows_environment_values_are_preserved(): void
    {
        $environment = AccessPowerShell::processEnvironment(
            'D:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'D:\\Windows',
            'D:\\Windows',
            'D:\\Windows\\System32\\cmd.exe',
        );

        $this->assertSame('D:\\Windows', $environment['SystemRoot']);
        $this->assertSame('D:\\Windows', $environment['WINDIR']);
        $this->assertSame('D:\\Windows\\System32\\cmd.exe', $environment['ComSpec']);
    }

    public function test_dao_opens_a_disposable_mdb_when_web_php_filters_the_inherited_environment(): void
    {
        $shell = AccessPowerShell::defaultPath();
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Windows DAO is unavailable.');
        }
        $directory = base_path('tmp/dao-environment-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $server = $_SERVER;
        $environment = $_ENV;
        try {
            $env = AccessPowerShell::processEnvironment($shell, getenv('SystemRoot') ?: null, getenv('WINDIR') ?: null, getenv('ComSpec') ?: null, $directory);
            $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', 'New-Object -ComObject DAO.DBEngine.120 -ErrorAction Stop | Out-Null'], null, $env);
            $probe->run();
            if (! $probe->isSuccessful()) {
                $this->markTestSkipped('Access DAO is unavailable.');
            }
            $copy = $directory.'/probe.mdb';
            copy(resource_path('mdb/synergee-empty.mdb'), $copy);
            // Symfony intersects OS variables with request $_SERVER variables.
            // A web request can therefore lose TEMP/TMP although getenv() has them.
            $_SERVER = ['PATH' => getenv('PATH')];
            $_ENV = [];
            $script = '$ErrorActionPreference = \'Stop\'; $dao = New-Object -ComObject DAO.DBEngine.120; '
                .'$db = $dao.OpenDatabase(\''.str_replace("'", "''", $copy).'\', $false, $true); '
                .'$db.Close(); Write-Output DAO_OPEN_OK';
            $process = new Process([$shell, '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode(iconv('UTF-8', 'UTF-16LE', $script))], null, $env);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('DAO_OPEN_OK', trim($process->getOutput()));
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;
            File::deleteDirectory($directory);
        }
    }
}
