<?php

namespace App\Services\Pulse;

use App\Enums\NotificationEvent;
use App\Enums\PermissionKey;
use App\Models\PulseResponse;
use App\Models\User;
use App\Notifications\PulseResponseSubmitted;
use App\Services\Notifications\NotificationService;

/**
 * The survey's public configuration and the one place a new response tells the admins.
 */
class PulseSurvey
{
    /**
     * The shape the public form fetches from GET /pulse/config.
     *
     * @return array{
     *     departments: list<array<string, string>>,
     *     options: array<string, list<string>>,
     *     impact: list<string>,
     *     frequency: list<string>,
     *     limits: array{problems: int, helps: int}
     * }
     */
    public function config(): array
    {
        $departments = [];

        foreach (config('pulse.departments') as $id => $department) {
            $departments[] = ['id' => $id, ...$department];
        }

        return [
            'departments' => $departments,
            'options' => config('pulse.options'),
            'impact' => config('pulse.impact'),
            'frequency' => config('pulse.frequency'),
            'limits' => config('pulse.limits'),
        ];
    }

    /**
     * 32 random bytes as 64 hex characters. Only the hash is stored.
     */
    public function newEditToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function departmentName(string $id): string
    {
        return (string) config("pulse.departments.{$id}.name", $id);
    }

    /**
     * Email every active person who may see the dashboard. Their own notification settings
     * decide which channels actually deliver, exactly like every other event in the app.
     */
    public function notifyAdmins(PulseResponse $response, NotificationService $notifications): void
    {
        $recipients = User::query()
            ->permission(PermissionKey::SurveyManage->value)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $notifications->send(
            $recipients,
            NotificationEvent::PulseResponseCreated,
            $response,
            [
                'title' => 'New Department Pulse response',
                'body' => "{$response->name} ({$response->departmentName()}) submitted the Department Pulse survey.",
                'url' => $this->dashboardUrl($response),
            ],
            new PulseResponseSubmitted($response),
        );
    }

    public function dashboardUrl(PulseResponse $response): string
    {
        return rtrim((string) config('incidents.frontend_url'), '/').'/admin/pulse?response='.$response->id;
    }
}
