<?php

namespace App\Services\Gpx;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;

class Parser
{
    public function parse(string $xml): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['gpx' => $message]);
        // UTF-16/32 input is rejected too, so declarations cannot bypass this check with NUL bytes.
        if (strlen($xml) > 10 * 1024 * 1024 || str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $fail('Use a UTF-8 GPX of at most 10 MB, without DTDs or entities.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            if (! $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) || $doc->doctype) {
                $fail('The GPX XML is malformed or contains a forbidden DTD.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if ($root?->localName !== 'gpx' || ! in_array($root->namespaceURI, [null, '', 'http://www.topografix.com/GPX/1/0', 'http://www.topografix.com/GPX/1/1'], true)) {
            $fail('Expected a GPX 1.0 or 1.1 document.');
        }
        $xpath = new DOMXPath($doc);
        $prefix = $root->namespaceURI ? 'g:' : '';
        if ($prefix) {
            $xpath->registerNamespace('g', $root->namespaceURI);
        }
        $points = $groups = $warnings = $seenCoordinates = $seenNames = [];
        $readGroup = function ($node, string $kind, string $id, string $name, string $pointTag) use ($xpath, $prefix, &$points, &$groups, &$warnings, &$seenCoordinates, &$seenNames, $fail): void {
            if (count($groups) >= 1000) {
                $fail('A GPX may contain at most 1,000 separate routes/segments.');
            }
            $ids = [];
            foreach ($xpath->query($prefix.$pointTag, $node) as $position => $point) {
                if (count($points) >= 10000) {
                    $fail('A GPX may contain at most 10,000 points.');
                }
                $pointId = 'p'.(count($points) + 1);
                $pointName = trim($xpath->evaluate('string('.$prefix.'name)', $point));
                $pointName = $pointName === '' ? $pointId : $pointName;
                $lat = $point->getAttribute('lat');
                $lon = $point->getAttribute('lon');
                $valid = is_numeric($lat) && is_numeric($lon) && is_finite((float) $lat) && is_finite((float) $lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180;
                $ele = trim($xpath->evaluate('string('.$prefix.'ele)', $point));
                $elevation = $ele !== '' && is_numeric($ele) && is_finite((float) $ele) ? (float) $ele : null;
                if (! $valid) {
                    $warnings[] = "$pointId ($pointName): invalid coordinates; adjacent edges are blocked, never bridged.";
                }
                if ($elevation === null) {
                    $warnings[] = "$pointId ($pointName): missing or invalid elevation.";
                }
                if ($valid) {
                    $key = json_encode([(float) $lat, (float) $lon]);
                    if (isset($seenCoordinates[$key])) {
                        $warnings[] = "$pointId ($pointName): duplicate coordinates of {$seenCoordinates[$key]}; retained for review.";
                    }
                    $seenCoordinates[$key] = $pointId;
                }
                if (isset($seenNames[$pointName])) {
                    $warnings[] = "$pointId: duplicate name '$pointName'; use point IDs to distinguish points.";
                }
                $seenNames[$pointName] = true;
                $points[$pointId] = ['id' => $pointId, 'name' => $pointName, 'lat' => $valid ? (float) $lat : null, 'lon' => $valid ? (float) $lon : null,
                    'elevation' => $elevation, 'valid' => $valid, 'group' => $id, 'position' => $position, 'kind' => $kind];
                $ids[] = $pointId;
            }
            if ($ids) {
                $groups[] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'points' => $ids];
            }
        };
        $readGroup($root, 'waypoints', 'wpt', 'Standalone waypoints', 'wpt');
        foreach ($xpath->query('/'.$prefix.'gpx/'.$prefix.'rte') as $i => $route) {
            $name = trim($xpath->evaluate('string('.$prefix.'name)', $route));
            $name = $name === '' ? 'Route '.($i + 1) : $name;
            $readGroup($route, 'route', 'r'.($i + 1), $name, 'rtept');
        }
        foreach ($xpath->query('/'.$prefix.'gpx/'.$prefix.'trk') as $i => $track) {
            $name = trim($xpath->evaluate('string('.$prefix.'name)', $track));
            $name = $name === '' ? 'Track '.($i + 1) : $name;
            foreach ($xpath->query($prefix.'trkseg', $track) as $j => $segment) {
                $readGroup($segment, 'track', 't'.($i + 1).'s'.($j + 1), $name.' / segment '.($j + 1), 'trkpt');
            }
        }
        if (! $points) {
            $fail('The GPX contains no waypoints, route points or track points.');
        }
        if (isset($points['p1']) && $groups[0]['kind'] === 'waypoints') {
            $warnings[] = 'Standalone waypoints have no connectivity. Define their sequences/branches explicitly; XML order is never used to connect them.';
        }

        return ['points' => $points, 'groups' => $groups, 'warnings' => $warnings];
    }
}
