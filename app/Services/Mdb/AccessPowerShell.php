<?php

namespace App\Services\Mdb;

use Symfony\Component\Process\Process;
use Throwable;

/** Match the PowerShell process architecture to the installed Access DAO engine. */
class AccessPowerShell
{
    /** Supply Windows' core process variables even when PHP is hosted by a service with a reduced environment. */
    public static function processEnvironment(string $powershellPath, ?string $systemRoot, ?string $windir, ?string $comSpec, ?string $temporaryDirectory = null): array
    {
        $windows = $systemRoot ?: $windir;
        if (! $windows && preg_match('~[\\\\/](?:SysWOW64|System32|Sysnative)[\\\\/]WindowsPowerShell[\\\\/]v1\.0[\\\\/]powershell\.exe$~i', $powershellPath, $match, PREG_OFFSET_CAPTURE)) {
            $windows = substr($powershellPath, 0, $match[0][1]);
        }
        if (! is_string($windows) || $windows === '') {
            throw new \RuntimeException('Unable to determine the Windows root directory for the MDB PowerShell worker. Set WINDIR or SystemRoot.');
        }

        $windows = rtrim($windows, '\\/');
        $temporaryDirectory = $temporaryDirectory ?: (getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir());

        return [
            'SystemRoot' => $windows,
            'WINDIR' => $windows,
            'ComSpec' => $comSpec ?: $windows.'\\System32\\cmd.exe',
            // ACE needs a writable temporary directory to initialize and open
            // databases. Symfony may omit these variables in a web PHP process.
            'TEMP' => $temporaryDirectory,
            'TMP' => $temporaryDirectory,
        ];
    }

    public static function defaultPath(): string
    {
        static $selected;
        if ($selected !== null) {
            return $selected;
        }
        $windows = getenv('WINDIR') ?: 'C:\\Windows';
        $legacy = $windows.'\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (PHP_OS_FAMILY !== 'Windows') {
            return $selected = $legacy;
        }
        $nativeDirectory = PHP_INT_SIZE === 4 && is_file($windows.'\\Sysnative\\reg.exe') ? 'Sysnative' : 'System32';
        $registry = $windows.'\\'.$nativeDirectory.'\\reg.exe';
        try {
            // Check the registered DLL itself: stale COM keys can survive removal
            // of an engine and must not select an unusable process architecture.
            foreach ([32 => $legacy, 64 => $windows.'\\'.$nativeDirectory.'\\WindowsPowerShell\\v1.0\\powershell.exe'] as $architecture => $path) {
                if (! is_file($path) || ! is_file($registry)) {
                    continue;
                }
                $process = new Process([$registry, 'query', 'HKCR\\CLSID\\{CD7791B9-43FD-42C5-AE42-8DD2811F0419}\\InprocServer32', '/ve', '/reg:'.$architecture]);
                $process->setTimeout(3);
                $process->run();
                if ($process->isSuccessful() && preg_match('/REG_(?:EXPAND_)?SZ\\s+([^\\r\\n]+)/', $process->getOutput(), $match)) {
                    $dll = trim($match[1], " \t\"");
                    $dll = preg_replace_callback('/%([^%]+)%/', fn ($part) => getenv($part[1]) ?: $part[0], $dll);
                    if (is_file($dll)) {
                        return $selected = $path;
                    }
                }
            }
        } catch (Throwable) {
            // Keep the existing configuration fallback when registration cannot
            // be inspected. Explicit MDB_*_POWERSHELL overrides always take priority.
        }

        return $selected = $legacy;
    }
}
