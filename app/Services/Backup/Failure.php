<?php

namespace App\Services\Backup;

/** Only fixed, curated messages from backup code may be written to the backup log. */
class Failure extends \RuntimeException {}
