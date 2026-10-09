<?php

namespace App\Services\Mdb;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;

class GpxParser
{
    public function parse(string $xml): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['source' => $message]);
        if (strlen($xml) > 10 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $fail('GPX exceeds 10 MB or contains a forbidden DTD/entity.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                $fail('Malformed GPX XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if ($root?->localName !== 'gpx' || ! in_array($root->namespaceURI, ['http://www.topografix.com/GPX/1/0', 'http://www.topografix.com/GPX/1/1'], true)) {
            $fail('Expected a GPX 1.0 or 1.1 document.');
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('g', $root->namespaceURI);
        $points = $warnings = [];
        foreach ($xpath->query('/g:gpx/g:wpt') as $i => $node) {
            if ($i >= 10000) {
                $fail('GPX exceeds 10,000 waypoints.');
            }
            $name = trim($xpath->evaluate('string(g:name)', $node));
            if ($name === '' || mb_strlen($name) > 100) {
                $fail('Every waypoint must have a text name of at most 100 characters.');
            }
            $lat = $node->getAttribute('lat');
            $lon = $node->getAttribute('lon');
            if (! is_numeric($lat) || ! is_numeric($lon) || ! is_finite((float) $lat) || ! is_finite((float) $lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                $fail("Waypoint {$name} has invalid WGS84 coordinates.");
            }
            $elevation = trim($xpath->evaluate('string(g:ele)', $node));
            if ($elevation !== '' && (! is_numeric($elevation) || ! is_finite((float) $elevation))) {
                $fail("Waypoint {$name} has invalid elevation.");
            }
            $time = trim($xpath->evaluate('string(g:time)', $node));
            if ($time !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $time)) {
                $fail("Waypoint {$name} timestamp must include an explicit UTC offset.");
            }
            try {
                if ($time !== '') {
                    new \DateTimeImmutable($time);
                    $dateErrors = \DateTimeImmutable::getLastErrors();
                    if ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) {
                        $fail("Waypoint {$name} timestamp contains an impossible calendar date or time.");
                    }
                }
                $recordedAt = $time === '' ? null : CarbonImmutable::parse($time)->utc()->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $fail("Waypoint {$name} has invalid timestamp.");
            }
            $points[] = ['name' => $name, 'latitude' => (float) $lat, 'longitude' => (float) $lon,
                'elevation' => $elevation === '' ? null : (float) $elevation, 'recorded_at' => $recordedAt,
                'description' => trim($xpath->evaluate('string(g:desc)', $node)),
                'original_entry' => ['xml' => $doc->saveXML($node), 'time' => $time, 'comment' => trim($xpath->evaluate('string(g:cmt)', $node))]];
        }
        if ($points === []) {
            $fail('GPX contains no waypoints; tracks and routes are not survey identifiers.');
        }
        $seen = [];
        foreach ($points as $point) {
            $key = 'name:'.$point['name'];
            if (isset($seen[$key])) {
                $other = $seen[$key];
                $warnings[] = ['code' => $point['latitude'] !== $other['latitude'] || $point['longitude'] !== $other['longitude'] ? 'conflicting_coordinates' : 'duplicate_waypoint', 'name' => $point['name']];
            }
            $seen[$key] = $point;
        }

        return ['waypoints' => $points, 'warnings' => $warnings];
    }
}
