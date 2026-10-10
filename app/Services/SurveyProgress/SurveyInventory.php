<?php

namespace App\Services\SurveyProgress;

class SurveyInventory
{
    public function key(string $name): ?string
    {
        if (! preg_match('/^([A-Za-z]+)_(\d{2})(\d{2})(\d{4})_(\d+)\.(pdf|gpx)$/iD', $name, $m)) {
            return null;
        }
        if (! checkdate((int) $m[3], (int) $m[2], (int) $m[4]) || (int) $m[5] < 1 || (int) $m[5] > 1000000) {
            return null;
        }

        return strtoupper($m[1]).'_'.$m[2].$m[3].$m[4].'_'.(int) $m[5];
    }

    public function analyze(array $files): array
    {
        $groups = [];
        $issues = [];
        $kmz = [];
        $submissions = [];
        foreach ($files as $file) {
            $kind = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($kind === 'kmz') {
                $kmz[] = $file;

                continue;
            }
            $key = $this->key($file['name']);
            if (! $key) {
                $issues[] = ['code' => 'invalid_filename', 'file' => $file['name']];

                continue;
            }
            $groups[$key][$kind][] = $file;
            $submission = substr($key, 0, strrpos($key, '_'));
            $submissions[$submission][$key] = true;
        }
        $pairs = [];
        foreach ($groups as $key => $types) {
            $submission = substr($key, 0, strrpos($key, '_'));
            if (count($submissions[$submission]) > 1) {
                $issues[] = ['code' => 'conflicting_submission_quantities', 'survey' => $key];

                continue;
            }
            if (empty($types['pdf']) || empty($types['gpx'])) {
                $issues[] = ['code' => 'unmatched_pdf_gpx', 'survey' => $key];

                continue;
            }
            $ambiguous = false;
            foreach (['pdf', 'gpx'] as $kind) {
                $copies = $types[$kind];
                if (count($copies) > 1) {
                    $issues[] = ['code' => 'duplicate_files_deduplicated', 'survey' => $key, 'kind' => $kind];
                }
                if (count($copies) > 1 && (count(array_column($copies, 'md5Checksum')) !== count($copies) || count(array_unique(array_column($copies, 'md5Checksum'))) !== 1 || empty($copies[0]['md5Checksum']))) {
                    $issues[] = ['code' => 'conflicting_duplicate_files', 'survey' => $key, 'kind' => $kind];
                    $ambiguous = true;
                }
            }
            if (! $ambiguous) {
                $pairs[$key] = ['pdf' => $types['pdf'][0], 'gpx' => $types['gpx'][0]];
            }
        }
        if (! $kmz) {
            $issues[] = ['code' => 'missing_kmz'];
        }
        $count = 0;
        $byGroup = [];
        $byDate = [];
        foreach (array_keys($pairs) as $key) {
            [$group, $date, $quantity] = explode('_', $key);
            $count += (int) $quantity;
            $byGroup[$group] = ($byGroup[$group] ?? 0) + (int) $quantity;
            $date = substr($date, 4, 4).'-'.substr($date, 2, 2).'-'.substr($date, 0, 2);
            $byDate[$date] = ($byDate[$date] ?? 0) + (int) $quantity;
        }

        return ['pairs' => $pairs, 'kmz' => $kmz, 'count' => $count, 'by_group' => $byGroup, 'by_date' => $byDate, 'issues' => $issues];
    }

    public function hash(array $files): string
    {
        usort($files, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
