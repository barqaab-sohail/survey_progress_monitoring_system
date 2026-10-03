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

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::ProjectManager => 'Project Manager',
            self::SurveyTeamLeader => 'Survey Team Leader',
            self::MdbTeamUser => 'MDB Team User',
            self::MdbProcessingUser => 'MDB Processing User',
            self::ManagementViewer => 'Management / Viewer',
        };
    }
}
