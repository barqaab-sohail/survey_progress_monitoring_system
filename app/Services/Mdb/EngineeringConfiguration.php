<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\Project;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** One engineering authority per project; derived values never overwrite survey observations. */
class EngineeringConfiguration
{
    public const CATEGORIES = ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'];

    /** Keep incomplete reference imports editable without granting engineering approval. */
    public function saveDraft(Project $project, Request $request): Configuration
    {
        $rules = [
            'revision' => 'required|integer|min:0', 'epsg' => 'nullable|integer|between:1000,999999',
            'crs_name' => 'nullable|string|max:255', 'projection_wkt' => 'nullable|string|max:255',
            'frequency_hz' => ['nullable', Rule::in([50, 60])],
            'nominal_voltage_kv' => 'nullable|numeric|gt:0|max:1000',
            'secondary_voltage_kv' => 'nullable|numeric|gt:0|max:1000',
            'template_id' => 'nullable|integer|exists:mdb_workflow_templates,id',
            'conductor_library_revision' => 'nullable|string|max:200',
            'transformer_library_revision' => 'nullable|string|max:200',
            'configuration_id' => 'nullable|string|max:200',
            'supply_conductor_id' => 'nullable|string|max:200', 'secondary_conductor_id' => 'nullable|string|max:200',
            'phase_spacing_cm' => 'nullable|numeric|gt:0|max:10000',
            'neutral_spacing_cm' => 'nullable|numeric|gt:0|max:10000',
            'conductor_height_m' => 'nullable|numeric|gt:0|max:200',
            'pole_height_unit' => 'nullable|in:ft,m', 'two_digit_year_start' => 'nullable|integer|between:1800,9900',
            'load_mode' => 'nullable|in:manual,category_rates', 'load_evidence' => 'nullable|string|max:4000',
            'blank_consumers_zero' => 'nullable|boolean',
            'mapping_json' => 'nullable|json|max:262144', 'transformer_types_json' => 'nullable|json|max:65536',
            'equipment_references_json' => 'nullable|json|max:262144',
            'reason' => 'required|string|min:5|max:4000',
        ];
        foreach (self::CATEGORIES as $category) {
            $rules['rates.'.$category.'.kva'] = 'nullable|numeric|min:0|max:1000000';
            $rules['rates.'.$category.'.power_factor'] = 'nullable|numeric|gt:0|max:1';
        }
        $data = $request->validate($rules);
        $objects = [];
        foreach (['mapping_json', 'transformer_types_json', 'equipment_references_json'] as $field) {
            if (isset($data[$field])) {
                $objects[$field] = $this->object($data[$field], $field);
            }
        }
        foreach ($objects['transformer_types_json'] ?? [] as $capacity => $type) {
            if (! is_numeric($capacity) || (float) $capacity <= 0 || ! is_string($type) || trim($type) === '' || strlen($type) > 200) {
                throw ValidationException::withMessages(['transformer_types_json' => 'Each positive kVA capacity must map to a nonempty equipment type ID. Library approval is checked when approving the configuration.']);
            }
        }
        if (isset($objects['equipment_references_json'])) {
            $objects['equipment_references_json'] = $this->equipmentReferences($objects['equipment_references_json']);
        }
        $storedEpsg = Configuration::where('project_id', $project->id)->value('epsg');
        if (isset($data['epsg']) && (int) $data['epsg'] !== (int) $storedEpsg) {
            try {
                app(ProjectionService::class)->projectMany([], (int) $data['epsg']);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['epsg' => $exception->getMessage()]);
            }
        }

        return DB::transaction(function () use ($project, $request, $data, $objects) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $configuration = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if (($configuration?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Another Admin updated these settings. Reload before saving.']);
            }
            $before = $configuration?->toArray() ?? [];
            $configuration ??= new Configuration(['project_id' => $project->id]);
            $settings = $configuration->settings ?? [];
            foreach (['frequency_hz', 'nominal_voltage_kv', 'secondary_voltage_kv', 'template_id', 'conductor_library_revision', 'transformer_library_revision'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settings[$key] = $data[$key];
                }
            }
            foreach (['pole_height_unit', 'two_digit_year_start'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settings['entry'][$key] = $data[$key];
                }
            }
            foreach (['crs_name', 'projection_wkt', 'configuration_id', 'supply_conductor_id', 'secondary_conductor_id', 'phase_spacing_cm', 'neutral_spacing_cm', 'conductor_height_m', 'blank_consumers_zero'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settings['engineering'][$key] = $data[$key];
                }
            }
            $settings['engineering']['version'] = 1;
            foreach (['mapping_json' => 'mapping', 'transformer_types_json' => 'transformer_types'] as $field => $key) {
                if (array_key_exists($field, $data)) {
                    if ($key === 'mapping') {
                        $settings[$key] = $objects[$field] ?? null;
                    } else {
                        $settings['engineering'][$key] = $objects[$field] ?? null;
                    }
                }
            }
            unset($settings['mapping']['reviewed_by'], $settings['mapping']['reviewed_at']);
            if (array_key_exists('equipment_references_json', $data)) {
                $settings['reference_import']['equipment_references'] = $objects['equipment_references_json'] ?? null;
            }
            if (isset($settings['reference_import'])) {
                $settings['reference_import']['status'] = 'needs_review';
            }
            $loads = $configuration->load_assumptions ?? [];
            foreach (['load_mode' => 'mode', 'load_evidence' => 'evidence', 'rates' => 'rates'] as $field => $key) {
                if (array_key_exists($field, $data)) {
                    $loads[$key] = $data[$field];
                }
            }
            unset($loads['method']);
            $configuration->fill(['settings' => $settings, 'load_assumptions' => $loads,
                'revision' => ($configuration->revision ?? 0) + 1, 'approved_by' => null, 'approved_at' => null]);
            if (array_key_exists('epsg', $data)) {
                $configuration->epsg = $data['epsg'];
            }
            $configuration->save();
            app(AuditService::class)->record($request->user(), 'mdb.configuration_draft_saved', $configuration, $before, $configuration->toArray(), $data['reason']);

            return $configuration;
        });
    }

    public function save(Project $project, Request $request): Configuration
    {
        $rules = [
            'revision' => 'required|integer|min:0', 'epsg' => 'required|integer|between:1000,999999',
            'crs_name' => 'required|string|max:255', 'projection_wkt' => 'nullable|string|max:255',
            'frequency_hz' => ['required', Rule::in([50, 60])],
            'nominal_voltage_kv' => 'required|numeric|gt:0|max:1000',
            'secondary_voltage_kv' => 'required|numeric|gt:0|max:1000',
            'template_id' => 'required|integer|exists:mdb_workflow_templates,id',
            'conductor_library_revision' => 'required|string|max:200',
            'transformer_library_revision' => 'required|string|max:200',
            'configuration_id' => 'required|string|max:200',
            'supply_conductor_id' => 'required|string|max:200', 'secondary_conductor_id' => 'required|string|max:200',
            'phase_spacing_cm' => 'required|numeric|gt:0|max:10000',
            'neutral_spacing_cm' => 'required|numeric|gt:0|max:10000',
            'conductor_height_m' => 'required|numeric|gt:0|max:200',
            'pole_height_unit' => 'required|in:ft,m', 'two_digit_year_start' => 'required|integer|between:1800,9900',
            'load_mode' => 'required|in:manual,category_rates', 'load_evidence' => 'required|string|max:4000',
            'blank_consumers_zero' => 'required|boolean',
            'mapping_json' => 'required|json|max:262144', 'transformer_types_json' => 'required|json|max:65536',
            'equipment_references_json' => 'nullable|json|max:262144',
            'reason' => 'required|string|min:5|max:4000', 'approved' => 'required|accepted',
        ];
        foreach (self::CATEGORIES as $category) {
            $required = $request->input('load_mode') === 'category_rates' ? 'required' : 'nullable';
            $rules['rates.'.$category.'.kva'] = $required.'|numeric|min:0|max:1000000';
            $rules['rates.'.$category.'.power_factor'] = $required.'|numeric|gt:0|max:1';
        }
        $data = $request->validate($rules);
        $mapping = $this->object($data['mapping_json'], 'mapping_json');
        $types = $this->object($data['transformer_types_json'], 'transformer_types_json');
        $references = isset($data['equipment_references_json'])
            ? $this->equipmentReferences($this->object($data['equipment_references_json'], 'equipment_references_json'))
            : null;
        $template = Template::findOrFail($data['template_id']);
        if (! $template->active || ! $template->approved_by || ! $template->approved_at) {
            throw ValidationException::withMessages(['template_id' => 'Select an active, approved MDB template.']);
        }
        $libraries = $template->metadata['equipment_references'] ?? [];
        foreach (['conductors', 'transformers', 'configurations'] as $library) {
            if (empty($libraries[$library]) || ! is_array($libraries[$library])) {
                throw ValidationException::withMessages(['template_id' => 'The template needs reviewed '.$library.' library references.']);
            }
        }
        if (! in_array($data['configuration_id'], $libraries['configurations'], true)) {
            throw ValidationException::withMessages(['configuration_id' => 'Select a configuration in the approved template library.']);
        }
        foreach (['supply_conductor_id', 'secondary_conductor_id'] as $key) {
            if (! in_array($data[$key], $libraries['conductors'], true)) {
                throw ValidationException::withMessages([$key => 'Choose a conductor in the approved template library.']);
            }
        }
        if ($types === []) {
            throw ValidationException::withMessages(['transformer_types_json' => 'Map at least one transformer capacity to its approved library type.']);
        }
        foreach ($types as $capacity => $type) {
            if (! is_numeric($capacity) || (float) $capacity <= 0 || ! is_string($type) || ! in_array($type, $libraries['transformers'], true)) {
                throw ValidationException::withMessages(['transformer_types_json' => 'Each positive kVA capacity must map to a type in the approved template library.']);
            }
        }
        validator($mapping, [
            'version' => ['required', Rule::in([ExportPayloadBuilder::VERSION, ExportPayloadBuilder::EXTENDED_VERSION])],
            'table_values' => 'required|array', 'networks' => 'present|array',
            'table_values.InstPrimaryTransformers' => 'present|array',
            'table_values.InstPrimaryTransformers.UseInstanceImpedance' => 'required|in:0,1',
        ])->validate();
        if ((int) $mapping['table_values']['InstPrimaryTransformers']['UseInstanceImpedance'] === 1) {
            validator($mapping['table_values']['InstPrimaryTransformers'], ['PercentImpedance' => 'required|numeric|gt:0', 'PercentResistance' => 'required|numeric|min:0'])->validate();
        }
        // PROJ also rejects geographic CRSs and units other than metres.
        try {
            app(ProjectionService::class)->projectMany([], (int) $data['epsg']);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['epsg' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($project, $request, $data, $mapping, $types, $template, $references) {
            // Serialize first setup as well as updates; project_id has a unique constraint.
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $configuration = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if (($configuration?->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Another Admin updated these settings. Reload before saving.']);
            }
            $before = $configuration?->toArray() ?? [];
            $configuration ??= new Configuration(['project_id' => $project->id]);
            $settings = $configuration->settings ?? [];
            if (array_key_exists('equipment_references_json', $data)) {
                $settings['reference_import']['equipment_references'] = $references;
            }
            foreach (['frequency_hz', 'nominal_voltage_kv', 'secondary_voltage_kv', 'conductor_library_revision', 'transformer_library_revision'] as $key) {
                $settings[$key] = $data[$key];
            }
            $settings['template_id'] = $template->id;
            $settings['entry'] = ['pole_height_unit' => $data['pole_height_unit'], 'two_digit_year_start' => (int) $data['two_digit_year_start']];
            $settings['engineering'] = array_intersect_key($data, array_flip(['crs_name', 'projection_wkt', 'configuration_id', 'supply_conductor_id', 'secondary_conductor_id', 'phase_spacing_cm', 'neutral_spacing_cm', 'conductor_height_m', 'blank_consumers_zero']));
            $settings['engineering']['transformer_types'] = $types;
            $settings['engineering']['version'] = 1;
            $mapping['reviewed_by'] = $request->user()->id;
            $mapping['reviewed_at'] = now('UTC')->toIso8601String();
            foreach (ExportPayloadBuilder::TABLES as $table) {
                if (! is_array($mapping['table_values'][$table] ?? null)) {
                    throw ValidationException::withMessages(['mapping_json' => 'Provide an object for table_values.'.$table.'.']);
                }
            }
            $mapping['table_values']['SAI_Control'] = array_replace($mapping['table_values']['SAI_Control'], [
                'Frequency' => (float) $data['frequency_hz'], 'LengthUnits' => 'Metric', 'Product' => $template->synergee_version,
                'ProjectionFile' => $data['crs_name'], 'ProjectionWKT' => $data['projection_wkt'] ?? '',
            ]);
            $mapping['table_values']['InstFeeders']['NominalKvll'] = (float) $data['nominal_voltage_kv'];
            $mapping['table_values']['InstPrimaryTransformers']['SpecNomKv'] = (float) $data['secondary_voltage_kv'];
            $mapping['table_values']['InstSection'] = array_replace($mapping['table_values']['InstSection'], [
                'ConfigurationId' => $data['configuration_id'], 'PhaseToPhaseSpacing_MUL' => (float) $data['phase_spacing_cm'],
                'PhaseToNeutralSpacing_MUL' => (float) $data['neutral_spacing_cm'], 'AveHeightAboveGround_MUL' => (float) $data['conductor_height_m'],
            ]);
            $settings['mapping'] = $mapping;
            $configuration->fill(['epsg' => $data['epsg'], 'settings' => $settings,
                'load_assumptions' => ['mode' => $data['load_mode'], 'method' => $data['load_mode'] === 'category_rates' ? 'Approved category kVA and power factor; integer customers balanced R/Y/B in order' : 'Explicit verified phase demand',
                    'evidence' => $data['load_evidence'], 'rates' => $data['rates'] ?? []],
                'revision' => ($configuration->revision ?? 0) + 1, 'approved_by' => $request->user()->id, 'approved_at' => now('UTC')])->save();
            app(AuditService::class)->record($request->user(), 'mdb.configuration_changed', $configuration, $before, $configuration->toArray(), $data['reason']);

            return $configuration;
        });
    }

    private function object(string $json, string $field): array
    {
        $object = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw ValidationException::withMessages([$field => 'Enter a JSON object.']);
        }

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    private function equipmentReferences(array $references): array
    {
        $rules = [];
        foreach (['conductors', 'transformers', 'configurations'] as $library) {
            $rules[$library] = 'present|array|list|max:10000';
            $rules[$library.'.*'] = 'required|string|max:200';
        }
        $validator = validator($references, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['equipment_references_json' => $validator->errors()->all()]);
        }

        return array_intersect_key($references, array_flip(['conductors', 'transformers', 'configurations']));
    }

    /** Apply centrally approved defaults to the frozen modelling view, retaining original entry rows. */
    public function apply(array $snapshot): array
    {
        $configuration = $snapshot['configuration'] ?? [];
        $settings = $configuration['settings'] ?? [];
        $engineering = $settings['engineering'] ?? null;
        if (! $engineering || empty($configuration['approved_by']) || empty($configuration['approved_at'])) {
            return $snapshot;
        }
        foreach ($snapshot['transformers'] as &$transformer) {
            foreach ($transformer['sections'] as &$section) {
                $section['equipment_ref'] = $engineering['configuration_id'];
                $phases = $section['phases'] ?? [];
                $hasLivePhase = is_array($phases) ? count(array_intersect(['R', 'Y', 'B'], $phases)) > 0 : preg_match('/[RYB]/', $phases) === 1;
                foreach ($section['consumers'] as &$consumer) {
                    if ($consumer['count'] === null && ! empty($engineering['blank_consumers_zero'])) {
                        $consumer['count'] = 0;
                    }
                    if (($configuration['load_assumptions']['mode'] ?? '') === 'category_rates' && $consumer['count'] !== null && $consumer['count'] >= 0 && $hasLivePhase) {
                        $consumer['demand'] = $this->demand((int) $consumer['count'], $consumer['category'], $phases, $configuration);
                    }
                }
                unset($consumer);
            }
            unset($section);
        }
        unset($transformer);

        return $snapshot;
    }

    public function demand(int $count, string $category, array|string $phases, array $configuration): array
    {
        $method = $configuration['load_assumptions'];
        $rate = $method['rates'][$category] ?? null;
        if (! is_numeric($rate['kva'] ?? null) || ! is_numeric($rate['power_factor'] ?? null) || $rate['kva'] < 0 || $rate['power_factor'] <= 0 || $rate['power_factor'] > 1) {
            throw ValidationException::withMessages(['engineering' => 'Configure an approved kVA rate and power factor for '.strtoupper($category).'.']);
        }
        $connected = array_values(array_filter(['R', 'Y', 'B'], fn ($phase) => is_array($phases) ? in_array($phase, $phases, true) : str_contains($phases, $phase)));
        if ($connected === []) {
            throw ValidationException::withMessages(['engineering' => 'Demand requires at least one connected live phase.']);
        }
        $values = [];
        foreach ($connected as $index => $phase) {
            $customers = intdiv($count, count($connected)) + ($index < $count % count($connected) ? 1 : 0);
            $kva = $customers * (float) $rate['kva'];
            $kw = $kva * (float) $rate['power_factor'];
            $values[$phase] = ['customers' => $customers, 'kw' => $kw, 'kvar' => sqrt(max(0, $kva * $kva - $kw * $kw)), 'kva' => $kva];
        }

        return ['method' => $method['method'], 'evidence' => $method['evidence'], 'approved_by' => $configuration['approved_by'],
            'configuration_revision' => $configuration['revision'], 'phase_values' => $values];
    }

    public function approved(int $projectId): Configuration
    {
        $configuration = Configuration::where('project_id', $projectId)->first();
        if (! $configuration?->approved_by || ! $configuration->approved_at || empty($configuration->settings['engineering'])) {
            throw ValidationException::withMessages(['engineering' => 'An Admin must approve the central Engineering Configuration before generating MDB files.']);
        }

        return $configuration;
    }
}
