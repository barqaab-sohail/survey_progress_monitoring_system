<?php

namespace Tests\Unit;

use App\Services\Mdb\SurveyWaypoint;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbSurveyIdentityTest extends TestCase
{
    public function test_examples_calendar_dates_and_explicit_year_window(): void
    {
        $identity = new SurveyWaypoint;
        foreach (['11131222104' => ['11', '2022-12-13', '104'], '01081026001' => ['01', '2026-10-08', '001']] as $identifier => [$group, $date, $gps]) {
            $this->assertSame((string) $identifier, $identity->compose($group, $date, $gps, 2000));
            $this->assertSame(['group_number' => $group, 'row_date' => $date, 'waypoint_reference' => $gps], $identity->parse((string) $identifier, 2000));
        }
        $this->assertSame('01081026002', $identity->compose('01', '2026-10-08', '002', 2000));
        $this->assertSame('1969-10-08', $identity->parse('01081069001', 1900)['row_date']);
        $this->assertSame('2069-10-08', $identity->parse('01081069001', 1970)['row_date']);
    }

    public function test_invalid_lengths_dates_and_missing_year_rule_are_rejected(): void
    {
        foreach ([['0108102600', 2000], ['010810260001', 2000], ['01310226001', 2000], ['01290225001', 2000], ['01081026001', null]] as [$value, $rule]) {
            try {
                (new SurveyWaypoint)->parse($value, $rule);
                $this->fail('Invalid complete waypoint was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame('01290224001', (new SurveyWaypoint)->compose('01', '2024-02-29', '001', 2000));
    }

    public function test_date_evidence_disambiguates_sources_without_rewriting_the_survey_date(): void
    {
        $points = [['id' => 1, 'name' => '104', 'source_file_id' => 1, 'recorded_at' => '2022-12-13T08:00:00Z'],
            ['id' => 2, 'name' => '104', 'source_file_id' => 2, 'recorded_at' => '2026-10-08T08:00:00Z']];
        $identity = new SurveyWaypoint;
        $this->assertSame(1, $identity->matches(['waypoint_reference' => '104', 'row_date' => '2022-12-13'], $points)[0]['id']);
        $this->assertCount(0, $identity->matches(['waypoint_reference' => '104', 'row_date' => '2024-10-08'], $points));
        $this->assertCount(0, $identity->matches(['waypoint_reference' => '104', 'row_date' => '2022-12-13'], [$points[1]]));
        $this->assertSame(2, $identity->matches(['waypoint_reference' => '104', 'row_date' => '2022-12-13', 'gpx_source_id' => 2], $points)[0]['id']);
    }

    public function test_conflicting_coordinates_for_one_composite_identity_cannot_be_merged(): void
    {
        $points = [1 => ['id' => 1, 'name' => '001', 'source_file_id' => 1, 'latitude' => 30, 'longitude' => 71],
            2 => ['id' => 2, 'name' => '001', 'source_file_id' => 2, 'latitude' => 31, 'longitude' => 71]];
        $transformer = ['source_waypoint_id' => 1, 'sections' => [['start_waypoint_id' => 1, 'end_waypoint_id' => 2,
            'original_entry' => ['start_survey_identifier' => '01081026001', 'end_survey_identifier' => '01081026001']]]];
        $this->expectException(ValidationException::class);
        (new SurveyWaypoint)->network($transformer, $points);
    }

    public function test_the_same_short_gps_point_does_not_merge_different_survey_identities(): void
    {
        $points = [1 => ['id' => 1, 'name' => '001', 'source_file_id' => 1, 'latitude' => 30, 'longitude' => 71]];
        $transformer = ['source_waypoint_id' => 1, 'header' => ['source_survey_identifier' => '01081022001'],
            'sections' => [['start_waypoint_id' => 1, 'end_waypoint_id' => 1, 'original_entry' => ['start_survey_identifier' => '01081022001', 'end_survey_identifier' => '01081026001']]]];
        $network = (new SurveyWaypoint)->network($transformer, $points);
        $this->assertNotSame($network['sections'][0]['start_waypoint_id'], $network['sections'][0]['end_waypoint_id']);
        $this->assertSame('survey:01081022001', $network['root']);
        $this->assertSame(1, $network['points']['survey:01081026001']['gpx_waypoint_id']);
        unset($transformer['header']['source_survey_identifier']);
        $this->expectException(ValidationException::class);
        (new SurveyWaypoint)->network($transformer, $points);
    }
}
