<?php

namespace App\Services\Gpx;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

class KmzExporter
{
    private const NS = 'http://www.opengis.net/kml/2.2';

    public function kml(string $name, array $source, array $settings, array $result): string
    {
        if (! $result['complete']) {
            throw new RuntimeException('Resolve span connections and calculation errors before exporting.');
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $add = function (DOMElement|DOMDocument $parent, string $tag, ?string $text = null) use ($doc): DOMElement {
            $element = $doc->createElementNS(self::NS, $tag);
            if ($text !== null) {
                $element->appendChild($doc->createTextNode($text));
            }
            $parent->appendChild($element);

            return $element;
        };
        // KML descriptions are interpreted as HTML by Earth, even when XML text is escaped.
        $describe = fn ($node, string $text) => $add($node, 'description', nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        $root = $add($doc, 'kml');
        $document = $add($root, 'Document');
        $add($document, 'name', $name);
        $summary = $result['label'].': '.json_encode($result['final_m']).' m / '.json_encode($result['final_km'])." km (application calculation).\n"
            .'Horizontal total: '.json_encode($result['horizontal_m']).' m. Straight included total: '.json_encode($result['straight_m'])
            .' m. Additional sag: '.json_encode($result['sag_additional_m'])." m.\n"
            .'Include elevation: '.($settings['include_elevation'] ? 'yes' : 'no').'; include sag: '.($settings['include_sag'] ? 'yes' : 'no')
            .'; missing elevation: '.($settings['missing_elevation'] ?? 'no fallback chosen').'; excluded spans: '.$result['excluded_spans'].".\n"
            ."Geometry: surveyed straight endpoint lines; sag is attributes only, no conductor curve.\n";
        $describe($document, $summary.$result['details']."\nWarnings:\n".implode("\n", $result['warnings']));
        // Follow KML's AbstractFeatureType sequence: description, styles, ExtendedData, features.
        foreach (['included' => 'ff009966', 'excluded' => 'ff888888'] as $id => $color) {
            $style = $add($document, 'Style');
            $style->setAttribute('id', $id);
            $line = $add($style, 'LineStyle');
            $add($line, 'color', $color);
            $add($line, 'width', '3');
        }
        $data = $add($document, 'ExtendedData');
        foreach (['survey' => $source['survey'] ?? null, 'settings' => $settings, 'totals' => array_diff_key($result, array_flip(['spans', 'warnings', 'details'])), 'warnings' => $result['warnings']] as $key => $value) {
            $field = $add($data, 'Data');
            $field->setAttribute('name', $key);
            $add($field, 'value', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        $coordinates = fn (array $p, bool $absolute): string => implode(',', array_map(fn ($v) => json_encode($v, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), [$p['lon'], $p['lat'], $absolute ? $p['elevation'] : 0.0]));
        $pointFolder = $add($document, 'Folder');
        $add($pointFolder, 'name', 'Surveyed points');
        foreach ($source['points'] as $point) {
            if (! $point['valid']) {
                continue;
            }
            $absolute = $settings['include_elevation'] && $point['elevation'] !== null;
            $placemark = $add($pointFolder, 'Placemark');
            $add($placemark, 'name', $point['name']);
            $describe($placemark, $summary."Point ID: {$point['id']}; name: {$point['name']}; segment: {$point['group']}; latitude: {$point['lat']}; longitude: {$point['lon']}; GPX elevation: "
                .($point['elevation'] === null ? 'missing' : $point['elevation'].' m').".\n"
                .($absolute ? 'Absolute altitude assumes GPX metres above mean sea level; datum unverified, no conversion. Survey elevation is not conductor attachment height.' : 'Clamped to ground; missing elevations are never inferred as zero for calculation.')
                ."\nWarnings: see document warnings (".count($result['warnings']).' warnings).');
            $geometry = $add($placemark, 'Point');
            $add($geometry, 'altitudeMode', $absolute ? 'absolute' : 'clampToGround');
            $add($geometry, 'coordinates', $coordinates($point, $absolute));
        }
        $folders = [];
        foreach ($result['spans'] as $span) {
            if (! $span['start']['valid'] || ! $span['end']['valid']) {
                continue;
            }
            if (! isset($folders[$span['group']])) {
                $folders[$span['group']] = $add($document, 'Folder');
                $add($folders[$span['group']], 'name', $span['group_name']);
            }
            $placemark = $add($folders[$span['group']], 'Placemark');
            $add($placemark, 'name', $span['start']['name'].' → '.$span['end']['name']);
            $absolute = $settings['include_elevation'] && $span['start']['elevation'] !== null && $span['end']['elevation'] !== null;
            $describe($placemark, $summary."\nSpan data (metres):\n".json_encode($span, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                ."\n".($absolute ? 'Absolute altitude: unverified GPX MSL datum, not attachment heights.' : 'Geometry clamped to ground; elevations remain in attributes.')."\nWarnings: ".implode('; ', $span['warnings']).'; also see document warnings.');
            $add($placemark, 'styleUrl', $span['final_m'] === null ? '#excluded' : '#included');
            $extended = $add($placemark, 'ExtendedData');
            foreach (['horizontal_m', 'elevation_difference_m', 'straight_m', 'sag_m', 'final_m', 'status'] as $key) {
                $field = $add($extended, 'Data');
                $field->setAttribute('name', $key);
                $add($field, 'value', $span[$key] === null ? 'unavailable' : (is_string($span[$key]) ? $span[$key] : json_encode($span[$key], JSON_THROW_ON_ERROR)));
            }
            $geometry = $add($placemark, 'LineString');
            $add($geometry, 'tessellate', $absolute ? '0' : '1');
            $add($geometry, 'altitudeMode', $absolute ? 'absolute' : 'clampToGround');
            $add($geometry, 'coordinates', $coordinates($span['start'], $absolute).' '.$coordinates($span['end'], $absolute));
        }

        return $doc->saveXML();
    }

    public function write(string $name, array $source, array $settings, array $result): string
    {
        $kml = $this->kml($name, $source, $settings, $result);
        $path = tempnam(sys_get_temp_dir(), 'gpx-kmz-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary KMZ.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the KMZ archive.');
            }
            if (! $zip->addFromString('doc.kml', $kml) || ! $zip->close()) {
                throw new RuntimeException('Could not write doc.kml to the KMZ.');
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }
}
