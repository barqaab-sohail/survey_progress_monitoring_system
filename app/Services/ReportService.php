<?php

namespace App\Services;

use App\Enums\SurveyItemStatus;
use App\Models\Feeder;
use App\Models\MdbDailyEntryItem;
use App\Models\MdbProcessingAssignment;
use App\Models\MdbProcessingDailyEntryItem;
use App\Models\SurveyDailyEntryItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public const TYPES = [
        'daily' => 'Daily Progress Report',
        'overall' => 'Overall Project Progress',
        'circle' => 'Circle-wise Progress',
        'division' => 'Division-wise Progress',
        'grid_station' => 'Grid Station-wise Progress',
        'feeder' => 'Feeder-wise Progress',
        'survey_team' => 'Survey Team Performance',
        'pending_verification' => 'Pending Survey Verification',
        'mdb_creation' => 'MDB Creation Report',
        'mdb_creation_backlog' => 'MDB Creation Backlog',
        'mdb_processing' => 'MDB Processing Report',
        'mdb_processing_backlog' => 'MDB Processing Backlog',
        'third_party' => 'Third-Party Processing Performance',
        'assignment_status' => 'Processing Assignment Status',
        'returned_survey' => 'Returned Survey Report',
    ];

    public function __construct(private readonly DashboardService $dashboard) {}

    public function generate(string $type, array $filters): array
    {
        $result = match ($type) {
            'daily' => $this->daily($filters),
            'overall' => $this->overall($filters),
            'circle', 'division', 'grid_station', 'feeder' => $this->geographic($type, $filters),
            'survey_team' => $this->surveyTeams(),
            'pending_verification' => $this->pendingVerification($filters),
            'mdb_creation' => $this->mdbCreation($filters),
            'mdb_creation_backlog' => $this->mdbBacklog($filters),
            'mdb_processing' => $this->mdbProcessing($filters),
            'mdb_processing_backlog' => $this->processingBacklog($filters),
            'third_party' => $this->thirdParty(),
            'assignment_status' => $this->assignmentStatus($filters),
            'returned_survey' => $this->returnedSurvey($filters),
            default => $this->overall($filters),
        };

        return ['title' => self::TYPES[$type] ?? self::TYPES['overall']] + $result;
    }

    private function daily(array $filters): array
    {
        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->startOfDay();
        $ids = $this->filteredFeeders($filters)->pluck('id');
        $survey = DB::table('survey_daily_entry_items as i')->join('survey_daily_entries as e', 'e.id', '=', 'i.survey_daily_entry_id')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])->whereIn('i.feeder_id', $ids)->whereIn('i.status', ['submitted', 'verified'])->select('e.entry_date')->selectRaw('SUM(i.transformers_surveyed) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');
        $verified = DB::table('survey_daily_entry_items')->whereBetween('verified_at', [$from, $to->copy()->endOfDay()])->whereIn('feeder_id', $ids)->where('status', 'verified')->selectRaw('DATE(verified_at) day')->selectRaw('SUM(transformers_surveyed) total')->groupBy(DB::raw('DATE(verified_at)'))->pluck('total', 'day');
        $mdb = DB::table('mdb_daily_entry_items as i')->join('mdb_daily_entries as e', 'e.id', '=', 'i.mdb_daily_entry_id')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])->whereIn('i.feeder_id', $ids)->select('e.entry_date')->selectRaw('SUM(i.mdb_files_created) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');
        $processed = DB::table('mdb_processing_daily_entry_items as i')->join('mdb_processing_daily_entries as e', 'e.id', '=', 'i.mdb_processing_daily_entry_id')->join('mdb_processing_assignments as a', 'a.id', '=', 'i.mdb_processing_assignment_id')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])->whereIn('a.feeder_id', $ids)->select('e.entry_date')->selectRaw('SUM(i.mdb_processed) total')->groupBy('e.entry_date')->pluck('total', 'entry_date');
        $rows = collect();
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $rows->push([$date->format('d M Y'), (int) ($survey[$key] ?? 0), (int) ($verified[$key] ?? 0), (int) ($mdb[$key] ?? 0), (int) ($processed[$key] ?? 0)]);
        }

        return ['headers' => ['Date', 'Survey Reported', 'Survey Verified', 'MDB Created', 'MDB Processed'], 'rows' => $rows];
    }

    private function overall(array $filters): array
    {
        $summary = $this->dashboard->summaryFor($this->progress($filters));
        $headers = ['Total Transformers', 'Survey Reported', 'Survey Verified', 'Survey Pending', 'Verification Pending', 'MDB Created', 'Creation Backlog', 'MDB Assigned', 'MDB Processed', 'Processing Backlog', 'Unassigned MDB'];
        $keys = ['total_transformers', 'survey_reported', 'survey_verified', 'survey_pending', 'verification_pending', 'mdb_created', 'mdb_creation_backlog', 'mdb_assigned', 'mdb_processed', 'mdb_processing_backlog', 'unassigned_mdb'];

        return ['headers' => $headers, 'rows' => [array_map(fn ($key) => $summary[$key], $keys)]];
    }

    private function geographic(string $type, array $filters): array
    {
        $feeders = $this->progress($filters);
        if ($type === 'feeder') {
            return ['headers' => $this->progressHeaders('Feeder'), 'rows' => $feeders->map(fn ($f) => $this->progressRow($f->feeder_code.' · '.$f->feeder_name, collect([$f])))->values()];
        }
        [$key, $label] = match ($type) {
            'circle' => ['circle_id', 'Circle'],
            'division' => ['division_id', 'Division'],
            default => ['grid_station_id', 'Grid Station'],
        };
        $rows = $feeders->groupBy($key)->map(function ($items) use ($type) {
            $first = $items->first();
            $name = match ($type) {
                'circle' => $first->circle?->name,
                'division' => $first->division?->name,
                default => $first->gridStation?->name,
            };

            return $this->progressRow($name ?: 'Unknown', $items);
        })->values();

        return ['headers' => $this->progressHeaders($label), 'rows' => $rows];
    }

    private function surveyTeams(): array
    {
        return ['headers' => ['Survey Team', 'Reported Today', 'Verified Today', 'Returned Rows', 'This Week', 'This Month', 'Overall'], 'rows' => $this->dashboard->surveyTeamPerformance()->map(fn ($r) => [$r->name, $r->reported_today, $r->verified_today, $r->returned, $r->this_week, $r->this_month, $r->overall])];
    }

    private function pendingVerification(array $filters): array
    {
        $rows = SurveyDailyEntryItem::query()->where('status', SurveyItemStatus::Submitted->value)->whereIn('feeder_id', $this->filteredFeeders($filters)->pluck('id'))
            ->whereHas('entry', fn (Builder $q) => $q->whereBetween('entry_date', [$filters['from'], $filters['to']])->when($filters['survey_team_id'] ?? null, fn ($x, $id) => $x->where('survey_team_id', $id)))
            ->with(['entry.team', 'entry.enteredBy', 'feeder'])->get()->map(fn ($i) => [$i->entry->entry_date->format('d M Y'), $i->entry->team->name, $i->feeder->feeder_code, $i->transformers_surveyed, $i->entry->enteredBy->name, $i->created_at->format('d M Y h:i A'), $i->drive_url ?: '—']);

        return ['headers' => ['Date', 'Survey Team', 'Feeder', 'Quantity', 'Submitted By', 'Submitted At', 'Drive URL'], 'rows' => $rows];
    }

    private function mdbCreation(array $filters): array
    {
        $rows = MdbDailyEntryItem::query()->whereIn('feeder_id', $this->filteredFeeders($filters)->pluck('id'))->whereHas('entry', fn (Builder $q) => $q->whereBetween('entry_date', [$filters['from'], $filters['to']]))->with(['entry.enteredBy', 'feeder'])->get()->map(fn ($i) => [$i->entry->entry_date->format('d M Y'), $i->feeder->feeder_code, $i->mdb_files_created, $i->entry->enteredBy->name, $i->drive_url ?: '—', $i->remarks ?: '—']);

        return ['headers' => ['Date', 'Feeder', 'MDB Created', 'Entered By', 'Drive URL', 'Remarks'], 'rows' => $rows];
    }

    private function mdbBacklog(array $filters): array
    {
        $rows = $this->progress($filters)->where('mdb_creation_backlog', '>', 0)->map(fn ($f) => [$f->feeder_code, $f->gridStation?->name, $f->survey_verified, $f->mdb_created, $f->mdb_creation_backlog])->values();

        return ['headers' => ['Feeder', 'Grid Station', 'Survey Verified', 'MDB Created', 'Creation Backlog'], 'rows' => $rows];
    }

    private function mdbProcessing(array $filters): array
    {
        $rows = MdbProcessingDailyEntryItem::query()->whereHas('entry', fn (Builder $q) => $q->whereBetween('entry_date', [$filters['from'], $filters['to']]))
            ->whereHas('assignment', fn (Builder $q) => $q->whereIn('feeder_id', $this->filteredFeeders($filters)->pluck('id'))->when($filters['organization_id'] ?? null, fn ($x, $id) => $x->where('organization_id', $id)))
            ->with(['entry.enteredBy', 'assignment.feeder', 'assignment.organization'])->get()->map(fn ($i) => [$i->entry->entry_date->format('d M Y'), $i->assignment->feeder->feeder_code, $i->assignment->organization->name, $i->mdb_processed, $i->entry->enteredBy->name, $i->output_drive_url ?: '—', $i->remarks ?: '—']);

        return ['headers' => ['Date', 'Feeder', 'Organization', 'MDB Processed', 'Entered By', 'Output URL', 'Remarks'], 'rows' => $rows];
    }

    private function processingBacklog(array $filters): array
    {
        $rows = $this->progress($filters)->where('mdb_processing_backlog', '>', 0)->map(fn ($f) => [$f->feeder_code, $f->gridStation?->name, $f->mdb_created, $f->mdb_assigned, $f->unassigned_mdb, $f->mdb_processed, $f->mdb_processing_backlog])->values();

        return ['headers' => ['Feeder', 'Grid Station', 'MDB Created', 'Assigned', 'Unassigned', 'Processed', 'Processing Backlog'], 'rows' => $rows];
    }

    private function thirdParty(): array
    {
        $rows = $this->dashboard->processingOrganizationPerformance()->where('type', 'third_party')->map(fn ($r) => [$r->name, $r->assigned, $r->processed, $r->remaining, $r->today, $r->this_week, $r->this_month, $r->completion.'%'])->values();

        return ['headers' => ['Organization', 'Assigned', 'Processed', 'Remaining', 'Today', 'This Week', 'This Month', 'Completion'], 'rows' => $rows];
    }

    private function assignmentStatus(array $filters): array
    {
        $rows = MdbProcessingAssignment::query()->whereIn('feeder_id', $this->filteredFeeders($filters)->pluck('id'))->whereBetween('assignment_date', [$filters['from'], $filters['to']])
            ->when($filters['organization_id'] ?? null, fn (Builder $q, $id) => $q->where('organization_id', $id))->when($filters['assignment_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->with(['feeder', 'organization', 'progressItems'])->get()->map(fn ($a) => [$a->assignment_date->format('d M Y'), $a->feeder->feeder_code, $a->organization->name, $a->assigned_quantity, $a->processed_quantity, $a->remaining_quantity, $a->target_date?->format('d M Y') ?: '—', strtoupper($a->status->value)]);

        return ['headers' => ['Assignment Date', 'Feeder', 'Organization', 'Assigned', 'Processed', 'Remaining', 'Target Date', 'Status'], 'rows' => $rows];
    }

    private function returnedSurvey(array $filters): array
    {
        $rows = SurveyDailyEntryItem::query()->where('status', SurveyItemStatus::Returned->value)->whereIn('feeder_id', $this->filteredFeeders($filters)->pluck('id'))
            ->whereHas('entry', fn (Builder $q) => $q->whereBetween('entry_date', [$filters['from'], $filters['to']])->when($filters['survey_team_id'] ?? null, fn ($x, $id) => $x->where('survey_team_id', $id)))
            ->with(['entry.team', 'entry.enteredBy', 'feeder'])->get()->map(fn ($i) => [$i->entry->entry_date->format('d M Y'), $i->entry->team->name, $i->feeder->feeder_code, $i->transformers_surveyed, $i->entry->enteredBy->name, $i->return_reason, $i->updated_at->format('d M Y h:i A')]);

        return ['headers' => ['Entry Date', 'Survey Team', 'Feeder', 'Quantity', 'Submitted By', 'Return Reason', 'Returned At'], 'rows' => $rows];
    }

    private function progress(array $filters): Collection
    {
        $allowed = $this->filteredFeeders($filters)->pluck('id');

        return $this->dashboard->feederProgress()->whereIn('id', $allowed)
            ->when($filters['progress_status'] ?? null, fn (Collection $items, $status) => $items->where('progress_status', $status))->values();
    }

    private function filteredFeeders(array $filters): Collection
    {
        return Feeder::query()
            ->when($filters['circle_id'] ?? null, fn (Builder $q, $id) => $q->where('circle_id', $id))
            ->when($filters['division_id'] ?? null, fn (Builder $q, $id) => $q->where('division_id', $id))
            ->when($filters['grid_station_id'] ?? null, fn (Builder $q, $id) => $q->where('grid_station_id', $id))
            ->when($filters['feeder_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['organization_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('processingAssignments', fn (Builder $a) => $a->where('organization_id', $id)))
            ->get(['id']);
    }

    private function progressHeaders(string $first): array
    {
        return [$first, 'Total', 'Survey Reported', 'Survey Verified', 'Survey Pending', 'Verification Pending', 'MDB Created', 'Creation Backlog', 'MDB Assigned', 'MDB Processed', 'Processing Backlog'];
    }

    private function progressRow(string $label, Collection $items): array
    {
        return [$label, ...array_map(fn ($key) => (int) $items->sum($key), ['total_transformers', 'survey_reported', 'survey_verified', 'survey_pending', 'verification_pending', 'mdb_created', 'mdb_creation_backlog', 'mdb_assigned', 'mdb_processed', 'mdb_processing_backlog'])];
    }
}
