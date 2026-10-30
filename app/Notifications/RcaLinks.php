<?php

namespace App\Notifications;

use App\Models\Incident;

/**
 * Links into the web app for RCA emails.
 */
final class RcaLinks
{
    public static function incident(Incident $incident): string
    {
        return config('incidents.frontend_url')."/incidents/{$incident->id}?tab=rca";
    }

    public static function actionItems(bool $everyone = false): string
    {
        return config('incidents.frontend_url').'/action-items?'.http_build_query(['scope' => $everyone ? 'overdue' : 'mine']);
    }
}
