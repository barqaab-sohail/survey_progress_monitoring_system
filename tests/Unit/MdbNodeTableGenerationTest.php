<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\AccessPowerShell;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\ReadbackVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\SynergeeSnapshot;
use Tests\TestCase;

class MdbNodeTableGenerationTest extends TestCase
{
    public static function snapshot(): array
    {
        $snapshot = SynergeeSnapshot::make();
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['configuration']['settings']['mapping']['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
        $snapshot['configuration']['settings']['mapping']['networks'] = [];
        $snapshot['configuration']['settings']['mapping']['table_values']['InstPrimaryTransformers'] = ['ConnectedPhases' => 'RYB'];
        $snapshot['configuration']['settings']['mapping']['table_values']['Node'] = ['IsPadMountGear' => 99];
        $snapshot['waypoints'][0] = array_replace($snapshot['waypoints'][0], ['latitude' => 34.163890, 'longitude' => 73.257410]);
        $snapshot['waypoints'][1] = array_replace($snapshot['waypoints'][1], ['latitude' => 34.163863, 'longitude' => 73.257402]);
        $transformer = &$snapshot['transformers'][0];
        $transformer['header']['feeder_identifier'] = '407';
        $section = $transformer['sections'][0];
        $section['conductors'] += ['Y' => '', 'B' => ''];
        $transformer['sections'] = [
            array_replace($section, ['start_waypoint_id' => null, 'end_waypoint_id' => 1, 'start_reference' => '104', 'end_reference' => '001',
                'original_entry' => ['start_survey_identifier' => '11131222104', 'end_survey_identifier' => '01081026001'],
                'consumers' => [['category' => 'rs', 'count' => 3]]]),
            array_replace($section, ['id' => 2, 'original_entry' => ['start_survey_identifier' => '01081026001', 'end_survey_identifier' => '01081026002'],
                'consumers' => [['category' => 'rs', 'count' => 10], ['category' => 'sc', 'count' => 4]]]),
        ];
        $transformer['entry_rows'] = [];
        foreach ([['S', 1, '11', '2022-12-13', '104', '11131222104'], ['E', 1, '01', '2026-10-08', '001', '01081026001'],
            ['S', 2, '01', '2026-10-08', '001', '01081026001'], ['E', 2, '01', '2026-10-08', '002', '01081026002']] as $i => $values) {
            [$se, $pair, $group, $date, $gps, $identity] = $values;
            $transformer['entry_rows'][] = ['id' => $i + 1, 'pair_number' => $pair, 'section_id' => $pair, 'designation' => $se,
                'group_number' => $group, 'row_date' => $date, 'waypoint_reference' => $gps, 'composite_identifier' => $identity,
                'gpx_source_id' => $i === 0 ? null : 1, 'pole_height' => 36, 'pole_height_unit' => 'ft'];
        }

        return $snapshot;
    }

    private function build(array $snapshot): array
    {
        $template = new Template($snapshot['template'] + ['active' => true]);
        $revision = new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]);

        return app(ExportPayloadBuilder::class)->build($revision, $template, 1, str_repeat('b', 64));
    }

    public function test_normal_payload_matches_placement_rules_keeps_string_suffixes_and_all_references(): void
    {
        $snapshot = self::snapshot();
        $before = $snapshot;
        $payload = $this->build($snapshot);
        $this->assertSame($before, $snapshot);
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        $this->assertCount(22, $nodes);
        $expected = ['X' => 339376.08162615, 'Y' => 3781699.94418096];
        foreach ($expected as $axis => $value) {
            $this->assertEqualsWithDelta($value, $nodes['81026001'][$axis], 0.000001);
            $this->assertEqualsWithDelta($value - 0.02, $nodes['131222104'][$axis], 0.000001);
            $this->assertSame($nodes['131222104'][$axis], $nodes['Synthetic feeder'][$axis]);
            $this->assertEqualsWithDelta($value - 0.01, $nodes['131222104.1'][$axis], 0.000001);
            foreach (range(1, 3) as $customer) {
                $this->assertEqualsWithDelta($value + 0.01, $nodes['81026001.'.$customer][$axis], 0.000001);
            }
        }
        $second = app(ProjectionService::class)->project(34.163863, 73.257402, 32643);
        $this->assertSame($second['x'], $nodes['81026002']['X']);
        foreach (range(1, 14) as $customer) {
            $node = $nodes['81026002.'.$customer];
            $this->assertSame('81026002.'.$customer, $node['NodeId']);
            $this->assertSame($second['x'] + 0.01, $node['X']);
            $this->assertSame($second['y'] + 0.01, $node['Y']);
            $this->assertSame('', $node['Description']);
        }
        $this->assertNotSame($nodes['81026002.1']['NodeId'], $nodes['81026002.10']['NodeId']);
        $this->assertSame('11131222104', $nodes['131222104']['Description']);
        $this->assertSame('01081026001', $nodes['81026001']['Description']);
        foreach ($nodes as $node) {
            $this->assertSame(['NodeId', 'X', 'Y', 'Description', 'IsPadMountGear'], array_keys($node));
            $this->assertSame(0, $node['IsPadMountGear']);
        }
        $this->assertSame('Synthetic feeder', $payload['tables']['InstFeeders'][0]['FeederId']);
        $this->assertSame('Synthetic grid', $payload['tables']['InstFeeders'][0]['SubstationId']);
        foreach ($payload['tables']['InstSection'] as $section) {
            foreach (['FeederId', 'FromNodeId', 'ToNodeId'] as $field) {
                $this->assertArrayHasKey($section[$field], $nodes);
            }
        }

        // Compare the entire normal payload's later tables with the old identity mapping.
        foreach ($snapshot['transformers'][0]['entry_rows'] as &$row) {
            unset($row['composite_identifier']);
        }
        unset($row);
        $baseline = $this->build($snapshot);
        foreach ($payload['tables'] as $table => $rows) {
            if (in_array($table, ['Node', 'InstSection', 'InstMymLoads', 'Loads'], true)) {
                continue;
            }
            $strip = function (array $rows) use ($table): array {
                foreach ($rows as &$row) {
                    foreach ($table === 'InstSection' ? ['FeederId', 'FromNodeId', 'ToNodeId'] : ($table === 'InstFeeders' ? ['FeederId'] : []) as $field) {
                        unset($row[$field]);
                    }
                    if (in_array($table, ['Loads', 'InstPrimaryTransformers', 'InstMeterPoints', 'InstDGens', 'InstMymDGens', 'InstMymLoads'], true)) {
                        unset($row['SectionId']);
                    }
                }

                return $rows;
            };
            $this->assertSame($strip($baseline['tables'][$table]), $strip($rows), $table.' business values changed');
        }
    }

    public static function mountingCases(): array
    {
        return [['Single Pole', 0], ['Double Pole', 0], ['Pad', 1], ['Pad-mounted', 1]];
    }

    #[DataProvider('mountingCases')]
    public function test_mounting_uses_verified_pdf_header_instead_of_global_default(string $mounting, int $expected): void
    {
        $snapshot = self::snapshot();
        $snapshot['transformers'][0]['header']['mounting'] = $mounting;
        foreach ($this->build($snapshot)['tables']['Node'] as $node) {
            $this->assertSame($expected, $node['IsPadMountGear']);
        }
    }

    public static function missingEvidence(): array
    {
        return [['missing_mounting'], ['ambiguous_mounting'], ['unverified'], ['unconfirmed_page'], ['false_text_page'],
            ['missing_pdf'], ['unreadable_pdf'], ['invalid_page'], ['fractional_page']];
    }

    #[DataProvider('missingEvidence')]
    public function test_missing_or_ambiguous_mounting_evidence_reports_the_affected_transformer(string $case): void
    {
        $snapshot = self::snapshot();
        $header = &$snapshot['transformers'][0]['header'];
        switch ($case) {
            case 'missing_mounting': $header['mounting'] = '';
                break;
            case 'ambiguous_mounting': $header['mounting'] = 'Single Pole / Pad';
                break;
            case 'unverified': $header['manually_verified'] = false;
                break;
            case 'unconfirmed_page': $header['pages'][0]['confirmed'] = false;
                break;
            case 'false_text_page': $header['pages'][0]['confirmed'] = 'false';
                break;
            case 'missing_pdf': $snapshot['sources'] = [];
                break;
            case 'unreadable_pdf': $snapshot['sources'][1]['status'] = 'failed';
                break;
            case 'invalid_page': $header['pages'][0]['page'] = 2;
                break;
            case 'fractional_page': $header['pages'][0]['page'] = 1.5;
                break;
        }
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Transformer T-SYNTHETIC:');
        $this->build($snapshot);
    }

    public function test_registered_first_s_placement_does_not_change_captured_coordinates_or_lengths(): void
    {
        $snapshot = self::snapshot();
        $snapshot['transformers'][0]['header'] += ['registered_transformer_id' => 77, 'starting_pole_coordinates' => [
            'version' => 1, 'source' => 'registered_transformer', 'registered_transformer_id' => 77,
            'latitude' => 34.16, 'longitude' => 73.25, 'elevation' => 5]];
        $before = $snapshot;
        $payload = $this->build($snapshot);
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        $this->assertEqualsWithDelta($nodes['81026001']['X'] - 0.02, $nodes['131222104']['X'], 1e-8);
        $this->assertEqualsWithDelta($nodes['81026001']['Y'] - 0.02, $nodes['131222104']['Y'], 1e-8);
        $this->assertSame(10.0, $payload['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertGreaterThan(100, $payload['provenance'][0]['span_length_m']);
        $this->assertSame($before, $snapshot);
    }

    public function test_conflicting_identity_and_wrong_crs_block_instead_of_guessing(): void
    {
        foreach (['identity', 'epsg'] as $case) {
            $snapshot = self::snapshot();
            if ($case === 'identity') {
                $snapshot['transformers'][0]['entry_rows'][0]['row_date'] = '2022-12-03';
            } else {
                $snapshot['configuration']['epsg'] = 32642;
            }
            try {
                $this->build($snapshot);
                $this->fail('Conflicting Node evidence must block export.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('export', $exception->errors());
            }
        }
    }

    public function test_colliding_feeder_and_waypoint_ids_cannot_silently_merge(): void
    {
        $snapshot = self::snapshot();
        $snapshot['transformers'][0]['header']['feeder_name'] = '131222104';
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('collides');
        $this->build($snapshot);
    }

    public function test_normal_native_mdb_writer_preserves_schema_values_and_relationships_for_each_mounting(): void
    {
        $shell = AccessPowerShell::defaultPath();
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Native Windows Access writer is unavailable.');
        }
        $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop";$engine=New-Object -ComObject DAO.DBEngine.120']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Access DAO is unavailable.');
        }
        $directory = base_path('tmp/node-native-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $templatePath = resource_path('mdb/synergee-empty.mdb');
        $hash = hash_file('sha256', $templatePath);
        try {
            foreach (['Single Pole', 'Double Pole', 'Pad'] as $index => $mounting) {
                $snapshot = self::snapshot();
                $snapshot['transformers'][0]['header']['mounting'] = $mounting;
                $payload = $this->build($snapshot);
                $input = $directory.'/'.$index.'.json';
                $output = $directory.'/'.$index.'.mdb';
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                $writer = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
                    base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', $templatePath, '-OutputPath', $output]);
                $writer->setTimeout(90);
                $writer->run();
                $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
                $result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $readback = app(ReadbackVerifier::class)->verify($output, $payload, $result);
                $this->assertTrue($readback['schema_verified']);
                $this->assertTrue($readback['references_verified']);
                $this->assertTrue($readback['mapped_values_verified']);
                $this->assertSame(22, $readback['counts']['Node']);
                $this->assertSame($hash, hash_file('sha256', $templatePath));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
