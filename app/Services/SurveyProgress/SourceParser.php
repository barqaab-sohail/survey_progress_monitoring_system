<?php

namespace App\Services\SurveyProgress;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class SourceParser
{
    private function xml(string $contents): DOMXPath
    {
        if (strlen($contents) > 25 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
            throw new RuntimeException('Unsafe or oversized XML source.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($contents, LIBXML_NONET)) {
                throw new RuntimeException('Malformed XML source.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function gpx(string $contents): array
    {
        $xpath = $this->xml($contents);
        $points = [];
        $issues = [];
        if ($xpath->document->documentElement->localName !== 'gpx') {
            throw new RuntimeException('Source is not GPX.');
        }
        foreach ($xpath->query('/*[local-name()="gpx"]/*[local-name()="wpt"]') as $node) {
            $name = trim($xpath->evaluate('string(./*[local-name()="name"])', $node));
            $latitude = $node->getAttribute('lat');
            $longitude = $node->getAttribute('lon');
            if (! preg_match('/^\d{11}$/D', $name) || ! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
                $issues[] = ['code' => 'invalid_complete_waypoint', 'reference' => $name];

                continue;
            }
            $elevation = trim($xpath->evaluate('string(./*[local-name()="ele"])', $node));
            $point = ['id' => $name, 'lat' => (float) $latitude, 'lon' => (float) $longitude, 'elevation' => $elevation !== '' && is_numeric($elevation) && is_finite((float) $elevation) ? (float) $elevation : null];
            $points[$name][] = $point;
            if (count($points[$name]) > 1) {
                $issues[] = ['code' => 'duplicate_waypoint', 'reference' => $name];
            }
        }
        if (! $points) {
            $issues[] = ['code' => 'no_complete_gpx_waypoints'];
        }

        return ['points' => $points, 'issues' => $issues];
    }

    public function kmz(string $path, ?string $feederCode = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Invalid KMZ archive.');
        }
        $roots = [];
        $issues = [];
        $total = 0;
        try {
            if ($zip->numFiles > 250) {
                throw new RuntimeException('KMZ has too many entries.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat['size'];
                if ($total > 50 * 1024 * 1024) {
                    throw new RuntimeException('KMZ decompression limit exceeded.');
                }
                if (strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)) !== 'kml') {
                    continue;
                }
                $xpath = $this->xml($zip->getFromIndex($i));
                foreach ($xpath->query('//*[local-name()="Placemark"]') as $node) {
                    $data = [];
                    foreach ($xpath->query('.//*[local-name()="Data" or local-name()="SimpleData"]', $node) as $field) {
                        $data[$field->getAttribute('name')] = trim($field->localName === 'Data' ? $xpath->evaluate('string(./*[local-name()="value"])', $field) : $field->textContent);
                    }
                    $description = trim($xpath->evaluate('string(./*[local-name()="description"])', $node));
                    if ($description !== '') {
                        $html = new DOMDocument;
                        $previous = libxml_use_internal_errors(true);
                        try {
                            $html->loadHTML('<?xml encoding="UTF-8">'.$description, LIBXML_NONET);
                            $htmlXPath = new DOMXPath($html);
                            foreach ($htmlXPath->query('//tr') as $row) {
                                $cells = $htmlXPath->query('./td|./th', $row);
                                if ($cells->length >= 2) {
                                    $data[trim($cells->item(0)->textContent)] = trim($cells->item(1)->textContent);
                                }
                            }
                        } finally {
                            libxml_clear_errors();
                            libxml_use_internal_errors($previous);
                        }
                    }
                    $attributes = [];
                    foreach ($data as $key => $value) {
                        $attributes[strtolower(preg_replace('/[^a-z0-9]/i', '', $key))] = $value;
                    }
                    if (strcasecmp($attributes['equiptype'] ?? '', 'Transformer') !== 0) {
                        continue;
                    }
                    $transformer = $attributes['equipmentnumber'] ?? null;
                    $reference = $attributes['gpsno'] ?? null;
                    if (! preg_match('/^\d{11}$/D', $reference ?? '')) {
                        $issues[] = ['code' => 'missing_kmz_waypoint_reference', 'transformer' => $transformer];

                        continue;
                    }
                    if (isset($attributes['polenumber']) && $attributes['polenumber'] !== $reference || $transformer && $transformer !== 'T-'.$reference) {
                        $issues[] = ['code' => 'conflicting_kmz_equipment_reference', 'reference' => $reference];

                        continue;
                    }
                    if ($feederCode !== null && (! isset($attributes['feedercode']) || $this->code($attributes['feedercode']) !== $this->code($feederCode))) {
                        $issues[] = ['code' => 'kmz_feeder_code_mismatch', 'reference' => $reference];

                        continue;
                    }
                    $coordinates = preg_split('/\s+/', trim($xpath->evaluate('string(./*[local-name()="Point"]/*[local-name()="coordinates"])', $node)));
                    $parts = explode(',', $coordinates[0] ?? '');
                    if (count($coordinates) !== 1 || count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1]) || abs((float) $parts[0]) > 180 || abs((float) $parts[1]) > 90) {
                        $issues[] = ['code' => 'invalid_kmz_transformer_point', 'reference' => $reference];

                        continue;
                    }
                    // KML clampToGround altitude zero is not a measured elevation.
                    $mode = trim($xpath->evaluate('string(./*[local-name()="Point"]/*[local-name()="altitudeMode"])', $node));
                    $elevation = $mode === 'absolute' && isset($parts[2]) && is_numeric($parts[2]) && is_finite((float) $parts[2]) && (float) $parts[2] !== 0.0 ? (float) $parts[2] : null;
                    $roots[$reference][] = ['id' => $reference, 'transformer_id' => $transformer, 'lat' => (float) $parts[1], 'lon' => (float) $parts[0], 'elevation' => $elevation];
                }
            }
        } finally {
            $zip->close();
        }
        if (! $roots) {
            $issues[] = ['code' => 'missing_kmz_transformer_references'];
        }

        return ['roots' => $roots, 'issues' => $issues];
    }

    private function code(string $code): string
    {
        $code = trim($code);

        return ctype_digit($code) ? (ltrim($code, '0') ?: '0') : strtoupper($code);
    }
}
