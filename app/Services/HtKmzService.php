<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\HtNetworkUpdate;
use App\Models\TransformerKmzImport;
use App\Support\FieldTransformerIdentity;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class HtKmzService
{
    private const NS = 'http://www.opengis.net/kml/2.2';

    public function baseline(int $feederId): ?TransformerKmzImport
    {
        return TransformerKmzImport::where('feeder_id', $feederId)->where('status', 'completed')->latest('id')->first();
    }

    public function path(TransformerKmzImport $import): string
    {
        $path = Storage::disk('local')->path($import->stored_path);
        abort_unless(is_file($path), 422, 'The original client KMZ is unavailable. Restore it before HT verification/export.');
        abort_if($import->file_sha256 && ! hash_equals($import->file_sha256, hash_file('sha256', $path)), 422, 'The original client KMZ checksum changed.');

        return $path;
    }

    private function document(string $path): DOMDocument
    {
        $xml = app(TransformerKmzImportService::class)->readKml($path);
        $xml = preg_replace('/\s+xsi:schemaLocation\s*=\s*(["\']).*?\1/is', '', $xml);
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            abort_unless(! str_contains(strtoupper($xml), '<!DOCTYPE') && $doc->loadXML($xml, LIBXML_NONET), 422, 'Invalid baseline KML.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc;
    }

    public function attributes(DOMElement $feature): array
    {
        $xp = new DOMXPath($feature->ownerDocument);
        $html = $xp->query('./*[local-name()="description"]', $feature)->item(0)?->textContent ?? '';
        preg_match_all('/<tr\b[^>]*>\s*<td\b[^>]*>(.*?)<\/td>\s*<td\b[^>]*>(.*?)<\/td>\s*<\/tr>/is', $html, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $match) {
            $label = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $attrs[$label] = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $attrs;
    }

    public function network(int $feederId): array
    {
        $baseline = $this->baseline($feederId);
        if (! $baseline) {
            return ['baseline_sha256' => null, 'features' => []];
        }
        $path = $this->path($baseline);
        $hash = hash_file('sha256', $path);

        return Cache::remember('ht-network:'.$baseline->id.':'.$hash, 3600, function () use ($path, $hash) {
            $doc = $this->document($path);
            $xp = new DOMXPath($doc);
            $features = [];
            foreach ($xp->query('//*[local-name()="Placemark"]') as $feature) {
                $attrs = $this->attributes($feature);
                $normal = [];
                foreach ($attrs as $k => $v) {
                    $normal[strtolower(preg_replace('/[^a-z0-9]/i', '', $k))] = $v;
                }
                if (strtolower($normal['linevoltage'] ?? '') !== '11kv') {
                    continue;
                }
                $node = $xp->query('.//*[local-name()="coordinates"]', $feature)->item(0);
                if (! $node) {
                    continue;
                }
                $points = [];
                foreach (preg_split('/\s+/', trim($node->textContent)) as $tuple) {
                    $coords = explode(',', $tuple);
                    if (count($coords) >= 2) {
                        $points[] = [(float) $coords[0], (float) $coords[1]];
                    }
                }
                $id = $feature->getAttribute('id');
                if ($id === '') {
                    continue;
                }
                $features[] = ['id' => $id, 'label' => $normal['equipmentnumber'] ?? $normal['sectionid'] ?? $normal['polenumber'] ?? $id,
                    'type' => $xp->query('.//*[local-name()="LineString"]', $feature)->length ? 'line' : 'point', 'coordinates' => $points];
            }

            return ['baseline_sha256' => $hash, 'features' => $features];
        });
    }

    public function reference(int $feederId): array
    {
        try {
            return $this->network($feederId);
        } catch (\Throwable $e) {
            report($e);

            return ['baseline_sha256' => null, 'features' => [], 'message' => 'HT reference unavailable. Restore the original client KMZ before verification.'];
        }
    }

    public function export(Feeder $feeder): string
    {
        $baseline = $this->baseline($feeder->id);
        abort_unless($baseline, 422, 'Import the client feeder KMZ before exporting HT updates.');
        $original = $this->path($baseline);
        $doc = $this->document($original);
        $xp = new DOMXPath($doc);
        $container = $xp->query('//*[local-name()="Document"]')->item(0);
        abort_unless($container, 422, 'Baseline KML has no Document.');
        $updates = HtNetworkUpdate::where('feeder_id', $feeder->id)->where('status', 'verified')->orderBy('id')->get();
        foreach ($updates as $update) {
            $p = $update->payload;
            $folder = $doc->createElementNS(self::NS, 'Folder');
            $folder->appendChild($doc->createElementNS(self::NS, 'name'))->appendChild($doc->createTextNode('Verified HT update '.$update->client_uuid));
            $container->appendChild($folder);
            $pointTemplate = $lineTemplate = $poleTemplate = null;
            foreach ($xp->query('//*[local-name()="Placemark"]') as $candidate) {
                $attrs = $this->attributes($candidate);
                if (in_array('Transformer', $attrs, true) && $xp->query('.//*[local-name()="Point"]', $candidate)->length) {
                    $pointTemplate ??= $candidate;
                }
                if ($xp->query('.//*[local-name()="Point"]', $candidate)->length) {
                    if (in_array('Primary Pole', $attrs, true)) {
                        $poleTemplate = $candidate;
                    } elseif (in_array('Secondary Pole', $attrs, true)) {
                        $poleTemplate ??= $candidate;
                    }
                }
                if ($xp->query('.//*[local-name()="LineString"]', $candidate)->length) {
                    $lineTemplate ??= $candidate;
                }
            }
            abort_unless($pointTemplate && $lineTemplate, 422, 'Baseline requires transformer and line templates for compatible export.');
            $remove = [];
            foreach ($xp->query('//*[local-name()="Placemark"]') as $candidate) {
                $attrs = $this->attributes($candidate);
                foreach ($attrs as $label => $value) {
                    if (strtolower(preg_replace('/[^a-z0-9]/i', '', $label)) === 'equipmentnumber'
                        && FieldTransformerIdentity::key($value) === $update->transformer_key
                        && in_array('Transformer', $attrs, true)) {
                        $remove[] = $candidate;
                        break;
                    }
                }
            }
            foreach ($remove as $candidate) {
                $candidate->parentNode->removeChild($candidate);
            }
            foreach ($p['nodes'] as $i => $node) {
                if ($i === 0) {
                    continue;
                }
                $last = $i === count($p['nodes']) - 1;
                $template = $last ? $pointTemplate : ($poleTemplate ?? $pointTemplate);
                $attrs = $this->attributes($template);
                $this->setAttributes($attrs, $p, $node, $last ? 'Transformer' : 'Primary Pole');
                $metadata = ['HtUpdateUuid' => $update->client_uuid, 'UpstreamFeatureId' => $p['upstream_feature_id'], 'UpstreamReference' => $p['upstream_reference']];
                $this->appendFeature($doc, $folder, $template, $update->client_uuid.'-p'.$i, $attrs, [$node], $metadata + ['Waypoint' => $node['waypoint']]);
                if ($p['kind'] === 'ht_extension') {
                    $attrs = $this->attributes($lineTemplate);
                    $this->setAttributes($attrs, $p, $node, null);
                    $this->appendFeature($doc, $folder, $lineTemplate, $update->client_uuid.'-l'.$i, $attrs, [$p['nodes'][$i - 1], $node],
                        $metadata + ['FromWaypoint' => $p['nodes'][$i - 1]['waypoint'], 'ToWaypoint' => $node['waypoint']]);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'ht-kmz-');
        if (! copy($original, $path)) {
            throw new \RuntimeException('Unable to create KMZ export.');
        }
        $zip = new ZipArchive;
        try {
            abort_unless($zip->open($path) === true, 500, 'Unable to open KMZ export.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.kml')) {
                    if (! $zip->addFromString($name, $doc->saveXML())) {
                        throw new \RuntimeException('Unable to write KML.');
                    }
                    break;
                }
            }
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $path;
    }

    private function setAttributes(array &$attrs, array $p, array $node, ?string $equipmentType): void
    {
        $phase = implode('-', array_keys(array_filter(['R' => $node['conductor_r'] ?? '', 'Y' => $node['conductor_y'] ?? '', 'B' => $node['conductor_b'] ?? ''])));
        $values = ['equipmenttype' => $equipmentType, 'equiptype' => $equipmentType,
            'equipmentphase' => $equipmentType === 'Transformer' ? $p['phase'] : '',
            'polephase' => $equipmentType === 'Transformer' ? $p['phase'] : $phase,
            'phase' => $phase, 'sectionphase' => $phase, 'poleuse' => 'Primary Distribution',
            'equipmentnumber' => $equipmentType === 'Transformer' ? $p['transformer_code'] : $node['waypoint'],
            'polenumber' => $node['waypoint'], 'gpsno' => $node['waypoint'], 'gpswaypointnumber' => $node['waypoint'],
            'equipmentsize' => $equipmentType === 'Transformer' ? $p['capacity_kva'] : '', 'equipsize' => $equipmentType === 'Transformer' ? $p['capacity_kva'] : '',
            'equipmentmake' => $equipmentType === 'Transformer' ? $p['make'] : '', 'equipmake' => $equipmentType === 'Transformer' ? $p['make'] : '',
            'equipmentlocation' => $p['location'], 'equipmentmounting' => $p['mounting'], 'equipmentstatus' => 'Connected',
            'linevoltage' => '11kV', 'linetype' => 'Primary', 'sectionid' => $p['client_uuid'].'-'.$node['waypoint'],
            'poletype' => $node['pole_type'] ?? '', 'polehight' => $node['pole_height'] ?? '', 'poleheight' => $node['pole_height'] ?? '',
            'consizea' => $node['conductor_r'] ?? '', 'consizeb' => $node['conductor_y'] ?? '', 'consizec' => $node['conductor_b'] ?? '',
            'conductorsizephaser' => $node['conductor_r'] ?? '', 'conductorsizephasey' => $node['conductor_y'] ?? '', 'conductorsizephaseb' => $node['conductor_b'] ?? '',
            'remarks' => $p['remarks'].' | HT update '.$p['client_uuid'].' | Upstream '.$p['upstream_reference'].' ('.($p['upstream_feature_id'] ?? '').')'];
        foreach ($attrs as $label => &$value) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $label));
            // Never copy another asset's coordinates, photographs, load or identifiers.
            if (array_key_exists($key, $values)) {
                $value = $values[$key] ?? '';
            } elseif (! in_array($key, ['substation', 'feeder', 'feedercode', 'polephase', 'poleuse', 'phase', 'sectionphase', 'shape'], true)) {
                $value = '';
            }
        }
    }

    private function appendFeature(DOMDocument $doc, DOMElement $folder, DOMElement $template, string $id, array $attrs, array $nodes, array $metadata): void
    {
        $feature = $template->cloneNode(true);
        $feature->setAttribute('id', 'field-'.$id);
        $xp = new DOMXPath($doc);
        foreach ($xp->query('./*[local-name()="description" or local-name()="ExtendedData"]', $feature) as $old) {
            $feature->removeChild($old);
        }
        $description = $doc->createElementNS(self::NS, 'description');
        $html = '<table>';
        foreach ($attrs as $key => $value) {
            $html .= '<tr><td>'.e($key).'</td><td>'.e((string) $value).'</td></tr>';
        }
        $description->appendChild($doc->createCDATASection($html.'</table>'));
        $feature->appendChild($description);
        $extended = $doc->createElementNS(self::NS, 'ExtendedData');
        foreach ($metadata as $key => $value) {
            $item = $doc->createElementNS(self::NS, 'Data');
            $item->setAttribute('name', $key);
            $item->appendChild($doc->createElementNS(self::NS, 'value'))->appendChild($doc->createTextNode((string) $value));
            $extended->appendChild($item);
        }
        $feature->appendChild($extended);
        foreach ($xp->query('.//*[local-name()="coordinates"]', $feature) as $coordinates) {
            $coordinates->nodeValue = implode(' ', array_map(fn ($n) => $n['longitude'].','.$n['latitude'].',0', $nodes));
        }
        foreach ($xp->query('./*[local-name()="name"]', $feature) as $name) {
            $name->nodeValue = $id;
        }
        $folder->appendChild($feature);
    }
}
