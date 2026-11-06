<?php

namespace App\Services\Mdb;

/** Static SAI_Control values transcribed from SAI_Control.xlsx (SAI_Control!A1:V2). */
class SaiControlTableGeneration
{
    public function row(?array $profile = null): array
    {
        return ($profile ?? (new MdbTableDefaults)->table('SAI_Control'))['values'];
    }
}
