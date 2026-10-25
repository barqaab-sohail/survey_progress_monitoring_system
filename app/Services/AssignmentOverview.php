<?php

namespace App\Services;

use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only view over existing assignments; shared allocations are labelled explicitly. */
class AssignmentOverview
{
    public const TYPES = [
        'mobile' => 'Mobile App Survey', 'entry' => 'Data Entry', 'verification' => 'Data Verification',
        'mdb' => 'MDB Creation', 'survey_team' => 'Survey Team Membership', 'mdb_team' => 'MDB Team Membership',
        'processing_team' => 'Processing Team Membership', 'feeder_pool' => 'Shared Survey Feeders',
        'processing' => 'MDB Processing',
    ];

    public const FIELDS = ['entry' => 'entry_assigned_to', 'verification' => 'verification_assigned_to', 'mdb' => 'mdb_assigned_to'];

    private function base(string $table): Builder
    {
        return DB::table($table)->addSelect('p.id as project_id', 'p.name as project_name');
    }

    private function label(Builder $query, string $source, string $type, string $allocation): Builder
    {
        $projectColumn = match ($source) {
            'mobile', 'membership', 'feeder_pool' => 't.project_id', 'processing' => 'f.project_id', default => 'a.project_id',
        };

        return $query->leftJoin('projects as p', 'p.id', '=', $projectColumn)
            ->selectRaw('? as source, ? as type, ? as allocation', [$source, $type, $allocation]);
    }

    public function query(User $user): Builder
    {
        $queries = [];
        $queries[] = $this->label($this->base('surveyor_assignments as a')
            ->leftJoin('survey_teams as t', 't.id', '=', 'a.survey_team_id')->leftJoin('feeders as f', 'f.id', '=', 'a.feeder_id')
            ->where('a.surveyor_id', $user->id), 'mobile', 'mobile', 'Direct task')
            ->addSelect('a.id as record_id', 'a.id as resource_id', 'a.survey_team_id as team_id',
                'f.feeder_code as title', 'f.feeder_name as description', 'a.status', 'a.assigned_at', 'a.deadline');

        foreach (self::FIELDS as $type => $field) {
            $queries[] = $this->label($this->base('mdb_workflow_batches as a')
                ->leftJoin('feeders as f', 'f.id', '=', 'a.feeder_id')->where('a.'.$field, $user->id), 'batch', $type, 'Direct task')
                ->addSelect('a.id as record_id', 'a.id as resource_id', 'a.survey_team_id as team_id',
                    'f.feeder_code as title', 'f.feeder_name as description', 'a.survey_status as status', 'a.created_at as assigned_at')
                ->selectRaw('NULL as deadline');

            // Historical workflow owners survive in the existing audit trail, not in new assignment records.
            $queries[] = $this->label($this->base('audit_logs as l')
                ->leftJoin('mdb_workflow_batches as a', 'a.id', '=', 'l.auditable_id')->leftJoin('feeders as f', 'f.id', '=', 'a.feeder_id')
                ->where('l.auditable_type', (new SurveyBatch)->getMorphClass())->where('l.action', 'mdb.workflow_task_assigned')
                ->where('l.old_values->'.$field, $user->id)->where(function ($q) use ($field, $user) {
                    $q->whereNull('l.new_values->'.$field)->orWhere('l.new_values->'.$field, '!=', $user->id);
                }), 'workflow_history', $type, 'Previous owner · audit history')
                ->addSelect('l.id as record_id', 'l.auditable_id as resource_id', 'a.survey_team_id as team_id',
                    'f.feeder_code as title', 'l.reason as description')
                ->selectRaw('? as status, l.created_at as assigned_at, NULL as deadline', ['reassigned']);
        }

        foreach (['survey' => 'survey', 'mdb' => 'mdb', 'processing' => 'processing'] as $prefix) {
            $queries[] = $this->label($this->base($prefix.'_team_members as m')
                ->join($prefix.'_teams as t', 't.id', '=', 'm.'.$prefix.'_team_id')->where('m.user_id', $user->id),
                'membership', $prefix.'_team', 'Team membership')
                ->addSelect('t.id as record_id', 't.id as resource_id', 't.id as team_id', 't.code as title', 't.name as description', 't.status', 'm.created_at as assigned_at')
                ->selectRaw('NULL as deadline');
        }

        $queries[] = $this->label($this->base('feeder_assignments as a')
            ->join('survey_teams as t', 't.id', '=', 'a.survey_team_id')->join('survey_team_members as m', 'm.survey_team_id', '=', 't.id')
            ->leftJoin('feeders as f', 'f.id', '=', 'a.feeder_id')->where('m.user_id', $user->id), 'feeder_pool', 'feeder_pool', 'Shared through survey team')
            ->addSelect('a.id as record_id', 'a.id as resource_id', 'a.survey_team_id as team_id', 'f.feeder_code as title', 't.name as description', 'a.status', 'a.start_date as assigned_at', 'a.end_date as deadline');

        $queries[] = $this->label($this->base('mdb_processing_assignments as a')
            ->leftJoin('feeders as f', 'f.id', '=', 'a.feeder_id')->leftJoin('processing_teams as t', 't.id', '=', 'a.processing_team_id')
            ->where(function ($q) use ($user) {
                $q->whereIn('a.processing_team_id', $user->processingTeams()->select('processing_teams.id'));
                if ($user->hasRole('mdb_processing_user') && $user->organization_id) {
                    $q->orWhere('a.organization_id', $user->organization_id);
                }
            }), 'processing', 'processing', 'Shared processing allocation')
            ->addSelect('a.id as record_id', 'a.id as resource_id', 'a.processing_team_id as team_id', 'f.feeder_code as title', 't.name as description', 'a.status', 'a.assignment_date as assigned_at', 'a.target_date as deadline');

        // Explicit column order is essential: UNION maps columns by position, not alias.
        $columns = ['source', 'type', 'allocation', 'record_id', 'resource_id', 'team_id', 'project_id', 'project_name', 'title', 'description', 'status', 'assigned_at', 'deadline'];
        $ordered = array_map(fn ($q) => DB::query()->fromSub($q, 'r')->select($columns), $queries);
        $union = array_shift($ordered);
        foreach ($ordered as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'allocations');
    }
}
