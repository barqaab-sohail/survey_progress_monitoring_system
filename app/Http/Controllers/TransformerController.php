<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Feeder;
use App\Models\Transformer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransformerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'feeder_id' => ['nullable', 'integer', 'exists:feeders,id'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'verification' => ['nullable', 'in:pending_client,pending_field,confirmed'],
        ]);

        $query = $this->visibleQuery($request->user())
            ->with(['feeder.gridStation', 'kmzImport']);

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('transformer_code', 'like', "%{$search}%")
                    ->orWhere('source_transformer_code', 'like', "%{$search}%")
                    ->orWhere('temporary_reference', 'like', "%{$search}%")
                    ->orWhere('gps_waypoint_number', 'like', "%{$search}%")
                    ->orWhere('equipment_location', 'like', "%{$search}%")
                    ->orWhere('source_feeder_name', 'like', "%{$search}%")
                    ->orWhereHas('feeder', fn (Builder $feeder) => $feeder
                        ->where('feeder_code', 'like', "%{$search}%")
                        ->orWhere('feeder_name', 'like', "%{$search}%"));
            });
        }
        if (! empty($filters['feeder_id'])) {
            $query->where('feeder_id', (int) $filters['feeder_id']);
        }
        if (($filters['capacity'] ?? '') !== '') {
            $query->where('capacity_kva', (float) $filters['capacity']);
        }
        if (($filters['verification'] ?? '') === 'pending_client') $query->where('code_verification_status', 'pending_client');
        if (($filters['verification'] ?? '') === 'pending_field') $query->where('capacity_verification_status', 'pending_field');
        if (($filters['verification'] ?? '') === 'confirmed') $query->where('code_verification_status', 'confirmed')->where('capacity_verification_status', 'confirmed');

        $summary = [
            'transformers' => (clone $query)->count(),
            'feeders' => (clone $query)->distinct()->count('feeder_id'),
            'capacity_kva' => (float) (clone $query)->sum('capacity_kva'),
            'pending' => (clone $query)->where(fn ($q) => $q->where('code_verification_status', 'pending_client')->orWhere('capacity_verification_status', 'pending_field'))->count(),
        ];
        $transformers = $query->orderBy('transformer_code')->paginate(40)->withQueryString();
        $feederIds = $this->visibleQuery($request->user())->select('feeder_id');
        $feeders = Feeder::query()->whereIn('id', $feederIds)
            ->orderBy('feeder_code')->get(['id', 'feeder_code', 'feeder_name']);
        $capacities = $this->visibleQuery($request->user())
            ->whereNotNull('capacity_kva')->distinct()->orderBy('capacity_kva')->pluck('capacity_kva');

        return view('transformers.index', compact('transformers', 'feeders', 'capacities', 'summary'));
    }

    public function show(Request $request, Transformer $transformer): View
    {
        $transformer = $this->visibleQuery($request->user())
            ->with(['feeder.gridStation', 'feeder.subDivision', 'kmzImport.importer', 'capacityObservations.observer', 'capacityObservations.reviewer', 'capacityObservations.survey.attachments', 'registryChanges.actor'])
            ->findOrFail($transformer->id);

        return view('transformers.show', compact('transformer'));
    }

    private function visibleQuery(User $user): Builder
    {
        if (! $user->hasAnyRole(['super_admin', 'project_manager', 'mdb_team_user']) && $user->hasRole('survey_data_verifier')) {
            return Transformer::where(fn ($q) => $q->whereHas('feeder', fn ($feeder) => $feeder->whereIn('project_id', $user->surveyTeams()->select('survey_teams.project_id')))
                ->orWhereIn('feeder_id', \App\Models\Mdb\SurveyBatch::where('verification_assigned_to', $user->id)->select('feeder_id')));
        }
        if (! $user->hasRole('super_admin') && $user->hasAnyRole(['mobile_survey_user', 'surveyor'])) {
            return Transformer::whereIn('feeder_id', app(\App\Services\FieldSurveyAccess::class)->feeders($user)->select('feeders.id'));
        }
        return Transformer::query()
            ->when(
                $user->hasRole(UserRole::SurveyTeamLeader->value)
                    && ! $user->hasRole(UserRole::SuperAdmin->value),
                fn (Builder $query) => $query->whereHas('feeder.assignments', fn (Builder $assignment) => $assignment
                    ->where('status', 'active')
                    ->whereHas('surveyTeam.members', fn (Builder $member) => $member->where('users.id', $user->id))),
            );
    }
}
