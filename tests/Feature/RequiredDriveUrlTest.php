<?php

namespace Tests\Feature;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\MdbDailyEntry;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\MdbCreationService;
use App\Services\SurveyProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RequiredDriveUrlTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_URL = 'https://drive.google.com/drive/folders/entry-evidence';

    private User $surveyAuthor;
    private User $mdbAuthor;
    private User $reviewer;
    private SurveyTeam $surveyTeam;
    private MdbTeam $mdbTeam;
    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        $internal = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $thirdParty = Organization::create(['name' => 'MDB Verification Organization', 'type' => 'third_party', 'status' => 'active']);
        $admin = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyAuthor = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->mdbAuthor = User::factory()->create(['organization_id' => $internal->id, 'role' => UserRole::MdbTeamUser]);
        $this->reviewer = User::factory()->create(['organization_id' => $thirdParty->id, 'role' => UserRole::MdbProcessingUser]);
        $project = Project::create(['code' => 'P1', 'name' => 'Project', 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F-01', 'feeder_name' => 'Evidence Feeder', 'total_transformers' => 100, 'baseline_pending' => false, 'processing_required' => true, 'status' => 'active']);
        $this->surveyTeam = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST-01', 'name' => 'Survey Team', 'status' => 'active']);
        $this->surveyTeam->members()->attach($this->surveyAuthor->id, ['is_leader' => true]);
        $this->mdbTeam = MdbTeam::create(['project_id' => $project->id, 'code' => 'MDB-01', 'name' => 'MDB Team', 'status' => 'active']);
        $this->mdbTeam->members()->attach($this->mdbAuthor->id);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->surveyTeam->id, 'assigned_by' => $admin->id, 'start_date' => today(), 'status' => 'active']);
    }

    public static function workflows(): array
    {
        return ['survey' => ['survey'], 'MDB' => ['mdb']];
    }

    #[DataProvider('workflows')]
    public function test_creation_requires_a_link_even_when_feeder_master_data_has_a_default(string $workflow): void
    {
        $this->prepareCapacity($workflow);
        $this->feeder->update(['survey_drive_url' => self::VALID_URL, 'mdb_drive_url' => self::VALID_URL]);
        $data = $this->entryData($workflow, 5);
        $data['entry_date'] = today()->subDay()->toDateString();
        $data['remarks'] = 'Keep the overall evidence notes';
        $data['items'][0]['remarks'] = 'Keep the feeder evidence notes';
        $this->actingAs($this->author($workflow))->from('/'.$workflow.'/create');
        foreach ($this->invalidLinks() as $link) {
            $invalid = $this->withInvalidLink($data, $link);
            $this->post('/'.$workflow, $invalid)->assertRedirect('/'.$workflow.'/create')
                ->assertSessionHasErrors('items.0.drive_url')->assertSessionHasInput('entry_date', $data['entry_date'])
                ->assertSessionHasInput('remarks', $data['remarks'])->assertSessionHasInput('items.0.remarks', $data['items'][0]['remarks']);
            $this->assertDatabaseCount($workflow.'_daily_entries', 0);
        }
        $response = $this->get('/'.$workflow.'/create')->assertOk()->assertSee('value="'.$data['entry_date'].'"', false)
            ->assertSee('value="5"', false)->assertSee($data['remarks'])->assertSee($data['items'][0]['remarks']);
        $this->assertRequiredInputs($response, 2);
        $this->post('/'.$workflow, $data)->assertRedirect(route($workflow.'.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas($workflow.'_daily_entry_items', ['feeder_id' => $this->feeder->id, 'drive_url' => self::VALID_URL]);
    }

    #[DataProvider('workflows')]
    public function test_editing_a_legacy_entry_requires_a_link_and_validation_does_not_change_saved_data(string $workflow): void
    {
        $this->prepareCapacity($workflow);
        $entry = $this->legacyEntry($workflow);
        $item = $entry->items->first();
        $this->assertNull($item->drive_url);
        $data = $this->entryData($workflow, 8);
        $data['remarks'] = 'Retain edited header notes';
        $data['items'][0]['remarks'] = 'Retain edited feeder notes';
        $editUrl = route($workflow.'.edit', $entry);
        $this->actingAs($this->author($workflow))->from($editUrl);
        foreach ($this->invalidLinks() as $link) {
            $this->put(route($workflow.'.update', $entry), $this->withInvalidLink($data, $link))
                ->assertRedirect($editUrl)->assertSessionHasErrors('items.0.drive_url')
                ->assertSessionHasInput('remarks', $data['remarks'])->assertSessionHasInput('items.0.remarks', $data['items'][0]['remarks']);
            $this->assertSame(5, $item->fresh()->getAttribute($this->quantityField($workflow)));
            $this->assertNull($item->fresh()->drive_url);
            $this->assertSame('Original legacy notes', $entry->fresh()->remarks);
            $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        }
        $response = $this->get($editUrl)->assertOk()->assertSee('value="8"', false)
            ->assertSee($data['remarks'])->assertSee($data['items'][0]['remarks']);
        $this->assertRequiredInputs($response, 2);
        $this->put(route($workflow.'.update', $entry), $data)->assertRedirect(route($workflow.'.index'))->assertSessionHasNoErrors();
        $this->assertSame(8, $item->fresh()->getAttribute($this->quantityField($workflow)));
        $this->assertSame(self::VALID_URL, $item->fresh()->drive_url);
    }

    #[DataProvider('workflows')]
    public function test_returned_correction_requires_a_link_and_keeps_input_and_returned_status_until_valid(string $workflow): void
    {
        $this->prepareCapacity($workflow);
        $entry = $this->legacyEntry($workflow);
        $item = $entry->items->first();
        if ($workflow === 'survey') {
            app(SurveyProgressService::class)->returnForCorrection($this->mdbAuthor, $item, 'Please include the corrected survey evidence.');
        } else {
            app(MdbCreationService::class)->returnForCorrection($this->reviewer, $item, 'Please include the corrected MDB evidence.');
        }
        $data = ['correction_item_id' => $item->id, $this->quantityField($workflow) => 7, 'remarks' => 'Keep the corrected evidence notes', 'drive_url' => self::VALID_URL];
        $returnedUrl = route($workflow.'.returned');
        $this->actingAs($this->author($workflow))->from($returnedUrl);
        foreach ($this->invalidLinks() as $link) {
            $invalid = $data;
            if ($link === null) {
                unset($invalid['drive_url']);
            } else {
                $invalid['drive_url'] = $link;
            }
            $this->put(route($workflow.'.resubmit', $item), $invalid)->assertRedirect($returnedUrl)->assertSessionHasErrors('drive_url')
                ->assertSessionHasInput($this->quantityField($workflow), 7)->assertSessionHasInput('remarks', $data['remarks']);
            $this->assertSame(SurveyItemStatus::Returned, $item->fresh()->status);
            $this->assertSame(5, $item->fresh()->getAttribute($this->quantityField($workflow)));
            $this->assertNull($item->fresh()->drive_url);
        }
        $response = $this->get($returnedUrl)->assertOk()->assertSee('value="7"', false)->assertSee($data['remarks']);
        $this->assertRequiredInputs($response, 1);
        $this->put(route($workflow.'.resubmit', $item), $data)->assertRedirect($returnedUrl)->assertSessionHasNoErrors();
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        $this->assertSame(7, $item->fresh()->getAttribute($this->quantityField($workflow)));
        $this->assertSame(self::VALID_URL, $item->fresh()->drive_url);
        $this->assertDatabaseCount($workflow.'_daily_entry_items', 1);
        $this->assertDatabaseCount($workflow === 'survey' ? 'survey_verification_history' : 'mdb_verification_histories', 2);
    }

    private function invalidLinks(): array
    {
        return [null, '', '   ', 'not-a-link', 'ftp://example.com/evidence', 'https://example.com/'.str_repeat('x', 2000)];
    }

    private function withInvalidLink(array $data, ?string $link): array
    {
        if ($link === null) {
            unset($data['items'][0]['drive_url']);
        } else {
            $data['items'][0]['drive_url'] = $link;
        }

        return $data;
    }

    private function author(string $workflow): User
    {
        return $workflow === 'survey' ? $this->surveyAuthor : $this->mdbAuthor;
    }

    private function quantityField(string $workflow): string
    {
        return $workflow === 'survey' ? 'transformers_surveyed' : 'mdb_files_created';
    }

    private function entryData(string $workflow, int $quantity): array
    {
        return ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, $this->quantityField($workflow) => $quantity, 'drive_url' => self::VALID_URL]]];
    }

    private function prepareCapacity(string $workflow): void
    {
        if ($workflow === 'mdb') {
            $survey = app(SurveyProgressService::class)->create($this->surveyAuthor, $this->surveyTeam, $this->entryData('survey', 50));
            app(SurveyProgressService::class)->verify($this->mdbAuthor, $survey->items->first());
        }
    }

    private function legacyEntry(string $workflow): SurveyDailyEntry|MdbDailyEntry
    {
        $data = $this->entryData($workflow, 5);
        unset($data['items'][0]['drive_url']);
        $data['remarks'] = 'Original legacy notes';

        return $workflow === 'survey'
            ? app(SurveyProgressService::class)->create($this->surveyAuthor, $this->surveyTeam, $data)
            : app(MdbCreationService::class)->create($this->mdbAuthor, $this->mdbTeam, $data);
    }

    private function assertRequiredInputs(TestResponse $response, int $minimumCount): void
    {
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $inputs = (new \DOMXPath($document))->query('//input[@type="url" and (@name="drive_url" or contains(@name,"[drive_url]"))]');
        $this->assertGreaterThanOrEqual($minimumCount, $inputs->length);
        foreach ($inputs as $input) {
            $this->assertTrue($input->hasAttribute('required'));
            $this->assertSame('2000', $input->getAttribute('maxlength'));
        }
    }
}
