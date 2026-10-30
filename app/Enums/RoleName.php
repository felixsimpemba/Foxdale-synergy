<?php

namespace App\Enums;

/**
 * The five roles that ship by default. Admins can add custom roles on top of these.
 */
enum RoleName: string
{
    case Reporter = 'reporter';
    case Responder = 'responder';
    case Noc = 'noc';
    case IncidentManager = 'incident_manager';
    case Admin = 'admin';

    /**
     * Default permission matrix (specification section 4). Scoping such as "own only" or
     * "team only" is enforced in policies, not by withholding the permission.
     *
     * @return list<PermissionKey>
     */
    public function defaultPermissions(): array
    {
        $reporter = [
            PermissionKey::IncidentCreate,
            PermissionKey::IncidentViewOwn,
            PermissionKey::IncidentUpdate,
            PermissionKey::DocumentUpload,
            PermissionKey::ProjectView,
            PermissionKey::TaskCreate,
            PermissionKey::TaskUpdate,
        ];

        $responder = [
            ...$reporter,
            PermissionKey::IncidentViewTeam,
            PermissionKey::IncidentAcknowledge,
            PermissionKey::IncidentTransition,
            PermissionKey::TaskAssign,
            PermissionKey::TaskReview,
            PermissionKey::TimeLog,
            PermissionKey::ResolutionWrite,
            PermissionKey::RcaWrite,
            PermissionKey::DashboardView,
            PermissionKey::ReportView,
            PermissionKey::AiUse,
        ];

        $noc = [
            ...array_filter($responder, fn (PermissionKey $key): bool => $key !== PermissionKey::RcaWrite),
            PermissionKey::IncidentViewAll,
            PermissionKey::IncidentSeverity,
            PermissionKey::IncidentAssign,
        ];

        $incidentManager = [
            ...$noc,
            PermissionKey::RcaWrite,
            PermissionKey::RcaApprove,
            PermissionKey::IncidentClose,
            PermissionKey::IncidentDelete,
            PermissionKey::ProjectManage,
            PermissionKey::TaskReview,
            PermissionKey::TaskDelete,
            PermissionKey::DocumentDelete,
            PermissionKey::KnowledgePublish,
            PermissionKey::ReportExport,
            PermissionKey::ReportSchedule,
            PermissionKey::RoutingManage,
        ];

        return array_values(match ($this) {
            self::Reporter => $reporter,
            self::Responder => $responder,
            self::Noc => $noc,
            self::IncidentManager => $incidentManager,
            // Admins can do everything except delete incidents.
            self::Admin => array_filter(
                PermissionKey::cases(),
                fn (PermissionKey $key): bool => $key !== PermissionKey::IncidentDelete,
            ),
        });
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this->value, config('incidents.auth.two_factor_required_roles'), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
