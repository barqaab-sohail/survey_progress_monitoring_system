<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use Throwable;

class GpxWaypointService
{
    public function read(string $path): array
    {
        if (! is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            $this->fail('The GPX file is unreadable or exceeds 10 MB.');
        }
        $xml = file_get_contents($path);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $this->fail('GPX documents containing DTDs or entities are not supported.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->fail('The GPX document is malformed.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if ($root?->localName !== 'gpx' || ! in_array($root->namespaceURI, ['http://www.topografix.com/GPX/1/1', 'http://www.topografix.com/GPX/1/0'], true)) {
            $this->fail('Upload a GPX 1.0 or 1.1 document.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('g', $root->namespaceURI);
        $waypoints = [];
        foreach ($xpath->query('/g:gpx/g:wpt') as $point) {
            if (count($waypoints) >= 10000) {
                $this->fail('A GPX may contain at most 10,000 named waypoints.');
            }
            $name = trim($xpath->evaluate('string(g:name)', $point));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 50) {
                $this->fail('GPX waypoint names must be at most 50 characters.');
            }
            $latitude = $point->getAttribute('lat');
            $longitude = $point->getAttribute('lon');
            if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
                || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
                $this->fail("Waypoint {$name} has invalid coordinates.");
            }
            $time = trim($xpath->evaluate('string(g:time)', $point));
            try {
                $date = $time === '' ? null : CarbonImmutable::parse($time)->setTimezone(config('app.timezone'))->toDateString();
            } catch (Throwable) {
                $this->fail("Waypoint {$name} has an invalid timestamp.");
            }
            $waypoints[] = ['name' => $name, 'date' => $date, 'latitude' => (float) $latitude, 'longitude' => (float) $longitude];
        }
        if ($waypoints === []) {
            $this->fail('No named GPX waypoints were found. Track points are not survey waypoint identifiers.');
        }

        return $waypoints;
    }

    public function resolve(array $waypoints, string $name, ?string $date): array
    {
        $matches = array_values(array_filter($waypoints, fn ($point) => $point['name'] === $name));
        if (count($matches) > 1 && $date) {
            $matches = array_values(array_filter($matches, fn ($point) => $point['date'] === $date));
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['rows' => count($matches) === 0
                ? "Waypoint '{$name}' was not found for this date. Upload the correct GPX or enter row coordinates."
                : "Waypoint '{$name}' is ambiguous. Use the survey row date to distinguish repeated waypoint names."]);
        }

        return $matches[0];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['gpx' => $message]);
    }
}
