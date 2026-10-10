<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case ProjectManager = 'project_manager';
    case SurveyTeamLeader = 'survey_team_leader';
    case MdbTeamUser = 'mdb_team_user';
    case MdbProcessingUser = 'mdb_processing_user';
    case ManagementViewer = 'management_viewer';
    case SurveyDataEntryOperator = 'survey_data_entry_operator';
    case SurveyDataVerifier = 'survey_data_verifier';
    case MdbGenerator = 'mdb_generator';
    case MdbVerifier = 'mdb_verifier';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::ProjectManager => 'Project Manager',
            self::SurveyTeamLeader => 'Survey Team Leader',
            self::MdbTeamUser => 'MDB User',
            self::MdbProcessingUser => 'Third-Party Processor',
            self::ManagementViewer => 'Management / Viewer',
            self::SurveyDataEntryOperator => 'Survey Data Entry Operator',
            self::SurveyDataVerifier => 'Survey Data Verifier',
            self::MdbGenerator => 'MDB Generator',
            self::MdbVerifier => 'MDB Verifier',
        };
    }
}
