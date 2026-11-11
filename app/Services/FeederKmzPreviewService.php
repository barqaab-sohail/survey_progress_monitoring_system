<?php

namespace App\Services;

use App\Models\Feeder;
use DOMDocument;
use DOMXPath;
use ZipArchive;

class FeederKmzPreviewService
{
    public function current(Feeder $feeder): array
    {
        $artifact = app(FeederKmzService::class)->generate($feeder);
        try {
            return $this->fromFile($artifact['path']) + ['counts' => $artifact['prepared']['counts'], 'warnings' => $artifact['prepared']['warnings']];
        } finally { @unlink($artifact['path']); }
    }

    /** Read the actual KMZ so the preview and download use identical geometry. */
    public function fromFile(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new \RuntimeException('Unable to read the KMZ preview.');
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower($zip->getNameIndex($i)), '.kml')) return $this->fromKml($zip->getFromIndex($i));
            }
            throw new \RuntimeException('The KMZ contains no KML document.');
        } finally { $zip->close(); }
    }

    public function fromKml(string $xml): array
    {
        $doc = new DOMDocument;
        if (str_contains(strtoupper($xml), '<!DOCTYPE') || ! $doc->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('Invalid preview KML.');
        $xp = new DOMXPath($doc);
        $features = []; $skipped = 0;
        foreach ($xp->query('//*[local-name()="Placemark"]') as $mark) {
            $details = app(HtKmzService::class)->attributes($mark);
            foreach ($xp->query('./*[local-name()="ExtendedData"]/*[local-name()="Data"]', $mark) as $field) {
                $details[$field->getAttribute('name')] = $xp->query('./*[local-name()="value"]', $field)->item(0)?->textContent ?? '';
            }
            foreach ($xp->query('./*[local-name()="ExtendedData"]//*[local-name()="SimpleData"]', $mark) as $field) {
                $details[$field->getAttribute('name')] = $field->textContent;
            }
            $lt = $xp->query('ancestor::*[local-name()="Folder"][*[local-name()="name" and text()="Verified LT networks"]]', $mark)->length > 0;
            $layer = $lt ? 'lt-'.($details['RegisteredTransformerId'] ?? $details['Transformer number'] ?? 'unknown') : 'ht';
            $label = $xp->query('./*[local-name()="name"]', $mark)->item(0)?->textContent ?: ($details['Equipment Number'] ?? $details['Section ID'] ?? 'HT feature');
            foreach ($xp->query('.//*[local-name()="Point" or local-name()="LineString" or local-name()="Polygon"]', $mark) as $geometry) {
                $type = $geometry->localName;
                $coordinates = []; $invalid = false;
                $nodes = $type === 'Polygon'
                    ? $xp->query('./*[local-name()="outerBoundaryIs" or local-name()="innerBoundaryIs"]//*[local-name()="coordinates"]', $geometry)
                    : $xp->query('./*[local-name()="coordinates"]', $geometry);
                foreach ($nodes as $node) {
                    $ring = [];
                    foreach (preg_split('/\s+/', trim($node->textContent)) as $tuple) {
                        $parts = explode(',', $tuple);
                        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1]) || abs((float) $parts[0]) > 180 || abs((float) $parts[1]) > 90) { $invalid = true; break; }
                        $ring[] = [(float) $parts[0], (float) $parts[1]];
                    }
                    if ($type === 'Polygon') {
                        if (count($ring) >= 3 && $ring[0] !== $ring[array_key_last($ring)]) $ring[] = $ring[0];
                        if (count($ring) >= 4) $coordinates[] = $ring;
                    } else $coordinates = $ring;
                }
                if ($invalid || ! $coordinates || ($type === 'Point' && count($coordinates) !== 1) || ($type === 'LineString' && count($coordinates) < 2)) { $skipped++; continue; }
                $features[] = ['type' => 'Feature', 'properties' => ['name' => $label, 'layer' => $layer,
                    'layer_label' => $lt ? 'LT - '.($details['Transformer number'] ?? 'Transformer') : 'Complete HT network',
                    'kind' => $lt ? 'lt' : 'ht', 'details' => $details],
                    'geometry' => ['type' => $type, 'coordinates' => $type === 'Point' ? $coordinates[0] : $coordinates]];
            }
        }
        return ['type' => 'FeatureCollection', 'features' => $features, 'skipped_geometries' => $skipped];
    }
}
