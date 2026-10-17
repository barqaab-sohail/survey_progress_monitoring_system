<?php

namespace App\Support;

use App\Models\SurveyTeam;

class SurveyTeamGroup
{
    public static function identifier(SurveyTeam $team): ?string
    {
        $numbers = [];
        foreach ([$team->code, $team->name] as $value) {
            if (preg_match('/([0-9]+)\s*$/D', (string) $value, $matches)) {
                $numbers[] = substr(str_pad($matches[1], 2, '0', STR_PAD_LEFT), -2);
            }
        }
        $numbers = array_values(array_unique($numbers));

        return count($numbers) === 1 ? $numbers[0] : null;
    }
}
