<?php

namespace App\Support;

class FieldTransformerIdentity
{
    public static function key(?string $code): ?string
    {
        $key = mb_strtoupper(preg_replace('/\s+/u', '', str_replace(['‐', '‑', '‒', '–', '—', '−'], '-', $code ?? '')));

        return $key === '' ? null : $key;
    }
}
