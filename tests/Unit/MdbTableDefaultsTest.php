<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\MdbTableDefaults;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbTableDefaultsTest extends TestCase
{
    public static function changes(): array
    {
        return ['version' => 1, 'tables' => [
            'InstMymSwDevPos' => ['values' => ['DeviceCode' => 'M', 'IsOpen' => '1'], 'first_year' => '2', 'last_year' => '4'],
            'SAI_Control' => ['values' => ['VoltageFactor' => '12', 'MYM_YearDesc_B' => '2026']],
            'InstSection' => ['values' => ['AmpRating' => '175'], 'options' => ['FirstPoleLengthM' => '12', 'CableClearanceFt' => '5', 'CableSlope' => '0.5']],
            'InstMeterPoints' => ['values' => ['Description' => 'Reviewed project meter', 'MeterIsOpen' => '0', 'Note_' => null]],
            'InstMymMeter' => ['values' => ['CalibSAIFI' => '2.5'], 'last_year' => '2'],
            'InstMeterDmd' => ['values' => ['PctPf' => '98', 'PctKwLoad' => '90'], 'seasons' => ['January'], 'day_types' => ['Weekday'],
                'hourly' => ['PctKwLoad' => ['01' => '0', '02' => '', '03' => '80']]],
            'Loads' => ['values' => ['CapacityFactor' => '0.75', 'LocationToModelSpotLoads' => 'C']],
            'InstMymLoads' => ['last_year' => '2'],
        ]];
    }

    public function test_profiles_are_typed_compact_and_keep_zero_distinct_from_blank(): void
    {
        $profile = app(MdbTableDefaults::class)->validate(self::changes());
        $this->assertSame(1, $profile['tables']['InstMymSwDevPos']['values']['IsOpen']);
        $this->assertSame(0, $profile['tables']['InstMeterPoints']['values']['MeterIsOpen']);
        $this->assertNull($profile['tables']['InstMeterPoints']['values']['Note_']);
        $this->assertSame(['01' => 0.0, '03' => 80.0], $profile['tables']['InstMeterDmd']['hourly']['PctKwLoad']);
        $this->assertSame(['January'], $profile['tables']['InstMeterDmd']['seasons']);
        $this->assertLessThan(7000, strlen(json_encode($profile)));
        $this->assertStringNotContainsString('SectionId', json_encode($profile));
        $this->assertStringNotContainsString('description', json_encode($profile));
    }

    public function test_invalid_fields_codes_ranges_and_unmanaged_ids_are_rejected(): void
    {
        foreach ([
            ['version' => 2],
            ['tables' => ['UnknownTable' => []]],
            ['tables' => ['InstMymSwDevPos' => ['values' => ['SectionId' => 'unsafe']]]],
            ['tables' => ['InstMymSwDevPos' => ['values' => ['DeviceCode' => str_repeat('X', 33)]]]],
            ['tables' => ['InstMymSwDevPos' => ['values' => ['IsOpen' => 2]]]],
            ['tables' => ['InstMymSwDevPos' => ['first_year' => 5, 'last_year' => 4]]],
            ['tables' => ['InstMymLoads' => ['last_year' => 11]]],
            ['tables' => ['SAI_Control' => ['values' => ['Phase1' => 'A']]]],
            ['tables' => ['Loads' => ['values' => ['CapacityFactor' => 75]]]],
            ['tables' => ['InstMeterDmd' => ['seasons' => []]]],
            ['tables' => ['InstMeterDmd' => ['seasons' => ['Invalid']]]],
            ['tables' => ['InstMeterDmd' => ['hourly' => ['PctKwLoad' => ['25' => 50]]]]],
            ['tables' => ['InstMeterDmd' => ['hourly' => ['PctKwLoad' => ['01' => -1]]]]],
            ['tables' => ['InstSection' => ['options' => ['CableSlope' => 0]]]],
            ['tables' => ['InstSection' => ['values' => ['PercentSpotLoadConstImpedance' => 90, 'PercentSpotLoadConstCurrent' => 30]]]],
        ] as $input) {
            try {
                app(MdbTableDefaults::class)->validate($input);
                $this->fail('Invalid table defaults accepted: '.json_encode($input));
            } catch (ValidationException $exception) {
                $this->assertStringStartsWith('mdb_table_defaults', array_key_first($exception->errors()));
            }
        }
    }

    public function test_existing_general_controls_remain_the_single_authority(): void
    {
        $profile = app(MdbTableDefaults::class)->forSettings(['frequency_hz' => 60,
            'engineering' => ['phase_spacing_cm' => 120, 'supply_conductor_id' => 'ANT'],
            'mapping' => ['table_values' => ['InstSection' => ['LoadConnectionPhaseGnd' => 0]]]], self::changes());
        $this->assertSame(60, $profile['tables']['SAI_Control']['values']['Frequency']);
        $this->assertEquals(120, $profile['tables']['InstSection']['values']['PhaseToPhaseSpacing_MUL']);
        $this->assertSame('ANT', $profile['tables']['InstSection']['options']['SupplyConductor']);
        $this->assertSame(0, $profile['tables']['InstSection']['values']['LoadConnectionPhaseGnd']);
    }

    public function test_first_save_preserves_previously_effective_load_defaults(): void
    {
        $settings = ['mapping' => ['table_values' => ['Loads' => ['CapacityFactor' => 0.4, 'IsSpotLoad' => 1]]]];
        $service = app(MdbTableDefaults::class);
        $profile = $service->forSettings($settings);
        $this->assertSame(0.4, $profile['tables']['Loads']['values']['CapacityFactor']);
        $this->assertSame(1, $profile['tables']['Loads']['values']['IsSpotLoad']);
        $changed = $service->forSettings($settings, ['tables' => ['Loads' => ['values' => ['CapacityFactor' => 0.7]]]]);
        $this->assertSame(0.7, $changed['tables']['Loads']['values']['CapacityFactor']);
    }

    public function test_frozen_defaults_reach_every_supported_generated_table_and_leave_legacy_json_unchanged(): void
    {
        $snapshot = MdbInstSectionGenerationTest::snapshot();
        $snapshot['configuration']['settings']['mdb_table_defaults'] = app(MdbTableDefaults::class)->validate(self::changes());
        $revision = new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]);
        $frozen = $revision->snapshot;
        $template = new Template($snapshot['template'] + ['active' => true]);
        $payload = app(ExportPayloadBuilder::class)->build($revision, $template, null, str_repeat('b', 64));
        $tables = $payload['tables'];
        $this->assertSame($frozen, $revision->snapshot);
        $this->assertEquals(12, $tables['SAI_Control'][0]['VoltageFactor']);
        $this->assertSame('2026', $tables['SAI_Control'][0]['MYM_YearDesc_B']);
        $this->assertSame([2, 3, 4], array_column($tables['InstMymSwDevPos'], 'YearNumber'));
        $this->assertSame([1], array_values(array_unique(array_column($tables['InstMymSwDevPos'], 'IsOpen'))));
        $this->assertEquals(175, $tables['InstSection'][0]['AmpRating']);
        $pole = collect($tables['InstSection'])->firstWhere('Description', 'Secondary Pole');
        $this->assertEquals(12, $pole['SectionLength_MUL']);
        $this->assertSame('Reviewed project meter', $tables['InstMeterPoints'][0]['Description']);
        $this->assertCount(3, $tables['InstMymMeter']);
        $this->assertEquals(2.5, $tables['InstMymMeter'][0]['CalibSAIFI']);
        $this->assertCount(1, $tables['InstMeterDmd']);
        $this->assertEquals(98, $tables['InstMeterDmd'][0]['PctPf']);
        $this->assertEquals(0, $tables['InstMeterDmd'][0]['PctKwLoad_Hour01']);
        $this->assertEquals(90, $tables['InstMeterDmd'][0]['PctKwLoad_Hour02']);
        $this->assertEquals(80, $tables['InstMeterDmd'][0]['PctKwLoad_Hour03']);
        $this->assertSame([0, 1, 2], array_values(array_unique(array_column($tables['InstMymLoads'], 'YearNumber'))));
        foreach ($tables['Loads'] as $load) {
            $this->assertEquals(0.75, $load['CapacityFactor']);
        }
        unset($snapshot['configuration']['settings']['mdb_table_defaults']);
        $legacyRevision = new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]);
        $legacy = app(ExportPayloadBuilder::class)->build($legacyRevision, $template, null, str_repeat('b', 64));
        $this->assertCount(11, $legacy['tables']['InstMymSwDevPos']);
        $this->assertSame(0, $legacy['tables']['InstMymSwDevPos'][0]['IsOpen']);
        $this->assertSame('132 KVA Abotabad', $legacy['tables']['InstMeterPoints'][0]['Description']);
        $this->assertCount(48, $legacy['tables']['InstMeterDmd']);
        $this->assertSame($snapshot, $legacyRevision->snapshot);
    }
}
