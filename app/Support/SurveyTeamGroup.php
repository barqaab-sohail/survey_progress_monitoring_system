<?php

namespace App\Support;

use App\Models\SurveyTeam;

class SurveyTeamGroup
{
    public static function filenameGroup(SurveyTeam $team): ?string
    {
        $group = self::identifier($team);
        if ($group === null) {
            return null;
        }

        $number = (int) $group;
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    public static function identifier(SurveyTeam $team): ?string
    {
        if ($team->group_code !== null) {
            return preg_match('/^[0-9]{2}$/D', $team->group_code) ? $team->group_code : null;
        }
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
