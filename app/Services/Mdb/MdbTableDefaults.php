<?php

namespace App\Services\Mdb;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Compact, versioned business defaults. Network identifiers are never configurable here. */
class MdbTableDefaults
{
    public function catalog(): array
    {
        static $catalog;

        return $catalog ??= json_decode(file_get_contents(resource_path('mdb/table-defaults-v1.json')), true, 512, JSON_THROW_ON_ERROR)['tables'];
    }

    /** Immutable fallback for approved JSON produced before configurable table defaults existed. */
    public function legacy(): array
    {
        return ['version' => 1, 'tables' => array_map(fn ($table) => $table['defaults'], $this->catalog())];
    }

    public function resolve(array $configuration): array
    {
        if (isset($configuration['settings']['mdb_table_defaults']) && ! is_array($configuration['settings']['mdb_table_defaults'])) {
            throw ValidationException::withMessages(['mdb_table_defaults' => 'MDB table settings must be a table dictionary.']);
        }

        return isset($configuration['settings']['mdb_table_defaults'])
            ? $this->validate($configuration['settings']['mdb_table_defaults']) : $this->legacy();
    }

    public function table(string $name): array
    {
        return $this->legacy()['tables'][$name];
    }

    /** Called on an explicit configuration save, never while reading an existing approval. */
    public function forSettings(array $settings, ?array $input = null): array
    {
        $profile = $this->resolve(['settings' => $settings]);
        // Loads already honoured editable mapping values before table forms existed.
        // Preserve those project choices when first adopting the new profile.
        if (! isset($settings['mdb_table_defaults'])) {
            $previous = $settings['mapping']['table_values']['Loads'] ?? [];
            $profile['tables']['Loads']['values'] = array_replace($profile['tables']['Loads']['values'],
                array_intersect_key($previous, $profile['tables']['Loads']['values']));
        }
        if ($input !== null) {
            $profile = $this->validate($input, $profile);
        }
        foreach ($this->catalog() as $table => $definition) {
            foreach (['values' => 'fields', 'options' => 'option_fields'] as $part => $fieldsKey) {
                foreach ($definition[$fieldsKey] ?? [] as $field => $meta) {
                    if (! isset($meta['managed_by'])) {
                        continue;
                    }
                    $key = $meta['managed_by'];
                    $value = match ($key) {
                        'frequency_hz' => $settings['frequency_hz'] ?? null,
                        'section_load_connection' => $settings['mapping']['table_values']['InstSection']['LoadConnectionPhaseGnd'] ?? null,
                        default => $settings['engineering'][$key] ?? null,
                    };
                    if ($value !== null && $value !== '') {
                        $profile['tables'][$table][$part][$field] = $value;
                    }
                }
            }
        }

        return $this->validate($profile);
    }

    public function validate(array $input, ?array $base = null): array
    {
        $catalog = $this->catalog();
        $rules = [
            'mdb_table_defaults' => 'array:version,tables',
            'mdb_table_defaults.version' => 'sometimes|required|in:1',
            'mdb_table_defaults.tables' => 'sometimes|array:'.implode(',', array_keys($catalog)),
        ];
        $labels = [];
        foreach ($catalog as $table => $definition) {
            $prefix = 'mdb_table_defaults.tables.'.$table;
            $defaults = $definition['defaults'];
            $rules[$prefix] = 'sometimes|array:'.implode(',', array_keys($defaults));
            foreach (['values' => 'fields', 'options' => 'option_fields'] as $part => $fieldsKey) {
                $fields = $definition[$fieldsKey] ?? [];
                if ($fields === []) {
                    if (array_key_exists($part, $defaults)) {
                        $rules[$prefix.'.'.$part] = 'sometimes|array|size:0';
                    }

                    continue;
                }
                $rules[$prefix.'.'.$part] = 'sometimes|array:'.implode(',', array_keys($fields));
                foreach ($fields as $field => $meta) {
                    $path = $prefix.'.'.$part.'.'.$field;
                    $rule = ['sometimes', $meta['nullable'] ? 'nullable' : 'required'];
                    $rule[] = match ($meta['type']) {
                        'text' => 'string', 'integer' => 'integer', 'choice' => Rule::in(array_keys($meta['choices'])), default => 'numeric',
                    };
                    if (isset($meta['min'])) {
                        $rule[] = 'min:'.$meta['min'];
                    }
                    if (isset($meta['max'])) {
                        $rule[] = 'max:'.$meta['max'];
                    }
                    if (! empty($meta['locked'])) {
                        $rule[] = Rule::in([$defaults[$part][$field]]);
                    }
                    $rules[$path] = $rule;
                    $labels[$path] = $table.' — '.$meta['label'].' ('.$field.')';
                }
            }
            if (isset($defaults['first_year'])) {
                foreach (['first_year', 'last_year'] as $key) {
                    $rules[$prefix.'.'.$key] = 'sometimes|required|integer|between:0,10';
                    $labels[$prefix.'.'.$key] = $table.' '.str_replace('_', ' ', $key).' index';
                }
            }
            foreach (['seasons', 'day_types'] as $key) {
                if (isset($defaults[$key])) {
                    $rules[$prefix.'.'.$key] = 'sometimes|required|array|list|min:1|max:'.count($defaults[$key]);
                    $rules[$prefix.'.'.$key.'.*'] = ['required', 'distinct', Rule::in($defaults[$key])];
                }
            }
            if (isset($defaults['hourly'])) {
                $groups = ['PctKwLoad', 'PctKvarLoad', 'PctKwDmd', 'PctKvarDmd'];
                $rules[$prefix.'.hourly'] = 'sometimes|array:'.implode(',', $groups);
                foreach ($groups as $group) {
                    $hours = array_map(fn ($hour) => sprintf('%02d', $hour), range(1, 24));
                    $rules[$prefix.'.hourly.'.$group] = 'sometimes|array:'.implode(',', $hours);
                    $rules[$prefix.'.hourly.'.$group.'.*'] = 'nullable|numeric|between:0,1000';
                    $labels[$prefix.'.hourly.'.$group.'.*'] = 'InstMeterDmd '.$group.' hourly percentage';
                }
            }
        }
        validator(['mdb_table_defaults' => $input], $rules, [], $labels)->validate();
        $profile = $base ?? $this->legacy();
        foreach ($input['tables'] ?? [] as $table => $parts) {
            foreach ($parts as $part => $value) {
                $profile['tables'][$table][$part] = in_array($part, ['values', 'options'], true)
                    ? array_replace($profile['tables'][$table][$part] ?? [], $value) : $value;
            }
        }
        foreach ($catalog as $table => $definition) {
            $entry = &$profile['tables'][$table];
            foreach (['values' => 'fields', 'options' => 'option_fields'] as $part => $fieldsKey) {
                foreach ($definition[$fieldsKey] ?? [] as $field => $meta) {
                    $value = $entry[$part][$field];
                    $entry[$part][$field] = $value === null || $value === '' ? null : match ($meta['type']) {
                        'integer', 'choice' => is_numeric($value) ? (int) $value : $value,
                        'number' => (float) $value,
                        default => $value,
                    };
                }
            }
            if (isset($entry['first_year'])) {
                $entry['first_year'] = (int) $entry['first_year'];
                $entry['last_year'] = (int) $entry['last_year'];
                if ($entry['last_year'] < $entry['first_year']) {
                    throw ValidationException::withMessages(['mdb_table_defaults.tables.'.$table.'.last_year' => $table.': last year index must be at least the first year index.']);
                }
            }
            if (isset($entry['hourly'])) {
                foreach ($entry['hourly'] as $group => &$hours) {
                    $hours = array_map(fn ($value) => (float) $value, array_filter($hours, fn ($value) => $value !== null && $value !== ''));
                }
                unset($hours);
                $entry['hourly'] = array_filter($entry['hourly'], fn ($hours) => $hours !== []);
            }
            // Constant power is the remainder; the combined impedance/current shares cannot exceed 100%.
            if ($table === 'InstSection') {
                foreach (['Dist', 'Spot'] as $kind) {
                    if (($entry['values']['Percent'.$kind.'LoadConstImpedance'] ?? 0) + ($entry['values']['Percent'.$kind.'LoadConstCurrent'] ?? 0) > 100) {
                        throw ValidationException::withMessages(['mdb_table_defaults.tables.InstSection.values.Percent'.$kind.'LoadConstCurrent' => 'InstSection: '.$kind.' constant impedance and current percentages together must not exceed 100%.']);
                    }
                }
            }
            unset($entry);
        }

        return $profile;
    }
}
