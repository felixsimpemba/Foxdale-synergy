<?php

namespace App\Enums;

enum PermissionKey: string
{
    case IncidentCreate = 'incident.create';
    case IncidentViewOwn = 'incident.view.own';
    case IncidentViewTeam = 'incident.view.team';
    case IncidentViewAll = 'incident.view.all';
    case IncidentUpdate = 'incident.update';
    case IncidentAcknowledge = 'incident.acknowledge';
    case IncidentTransition = 'incident.transition';
    case IncidentSeverity = 'incident.severity';
    case IncidentAssign = 'incident.assign';
    case IncidentDelete = 'incident.delete';
    case IncidentClose = 'incident.close';
    case TimeLog = 'time.log';
    case DocumentUpload = 'document.upload';
    case DocumentDelete = 'document.delete';
    case KnowledgePublish = 'knowledge.publish';
    case ResolutionWrite = 'resolution.write';
    case RcaWrite = 'rca.write';
    case RcaApprove = 'rca.approve';
    case DashboardView = 'dashboard.view';
    case ReportView = 'report.view';
    case ReportExport = 'report.export';
    case ReportSchedule = 'report.schedule';
    case AiUse = 'ai.use';
    case RoutingManage = 'routing.manage';
    case UserManage = 'user.manage';
    case IntegrationManage = 'integration.manage';
    case AuditView = 'audit.view';

    public function description(): string
    {
        return match ($this) {
            self::IncidentCreate => 'Report incidents',
            self::IncidentViewOwn => 'View incidents reported by or assigned to the user',
            self::IncidentViewTeam => 'View incidents on services owned by the user\'s teams',
            self::IncidentViewAll => 'View all incidents',
            self::IncidentUpdate => 'Post updates and comments',
            self::IncidentAcknowledge => 'Acknowledge incidents',
            self::IncidentTransition => 'Change incident status up to resolved',
            self::IncidentSeverity => 'Change incident severity',
            self::IncidentAssign => 'Reassign and escalate incidents',
            self::IncidentDelete => 'Soft delete incidents with a reason',
            self::IncidentClose => 'Close resolved incidents',
            self::TimeLog => 'Log work time',
            self::DocumentUpload => 'Upload documents',
            self::DocumentDelete => 'Delete any document (uploaders can always delete their own)',
            self::KnowledgePublish => 'Publish resolutions as reviewed knowledge base articles',
            self::ResolutionWrite => 'Write fix documentation',
            self::RcaWrite => 'Write root cause analyses',
            self::RcaApprove => 'Approve or reject root cause analyses',
            self::DashboardView => 'View dashboards',
            self::ReportView => 'View reports',
            self::ReportExport => 'Export reports',
            self::ReportSchedule => 'Schedule reports',
            self::AiUse => 'Use AI suggestions',
            self::RoutingManage => 'Manage routing rules, on-call schedules and escalation policies',
            self::UserManage => 'Manage users, roles, teams and services',
            self::IntegrationManage => 'Manage API keys, chat channels, AI and system settings',
            self::AuditView => 'View the audit log',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
