<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Keep PHP alive long enough for a bounded external worker and its cleanup. */
class ExecutionTimeLimit
{
    public function extend(int $workerSeconds): void
    {
        $current = (int) ini_get('max_execution_time');
        if ($current === 0) {
            return; // CLI workers and explicitly unlimited PHP already allow this operation.
        }
        $required = max($current, max(1, $workerSeconds) + 60);
        // Restart the timer immediately before the operation: preprocessing may
        // have consumed part of the request's original allowance on Windows.
        if (function_exists('set_time_limit') && @set_time_limit($required)) {
            return;
        }
        if ($current < $required) {
            throw ValidationException::withMessages(['file' => 'PHP cannot extend its execution limit for MDB generation. Set max_execution_time to at least '.$required.' seconds in the web server PHP configuration, restart PHP/Apache, and retry.']);
        }
    }
}
