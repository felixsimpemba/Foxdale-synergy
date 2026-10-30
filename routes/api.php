<?php

use App\Http\Controllers\Api\V1\Admin\ApiKeyController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Incidents\IncidentController;
use App\Http\Controllers\Api\V1\Incidents\IncidentLinkController;
use App\Http\Controllers\Api\V1\Incidents\IncidentResolutionController;
use App\Http\Controllers\Api\V1\Incidents\IncidentTimelineController;
use App\Http\Controllers\Api\V1\Incidents\IncidentUpdateController;
use App\Http\Controllers\Api\V1\Incidents\IncidentWatcherController;
use App\Http\Controllers\Api\V1\Incidents\IncidentWorkflowController;
use App\Http\Controllers\Api\V1\Incidents\TimeEntryController;
use App\Http\Controllers\Api\V1\Knowledge\DocumentController;
use App\Http\Controllers\Api\V1\Knowledge\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\Knowledge\RunbookController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationLogController;
use App\Http\Controllers\Api\V1\Notifications\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Notifications\PushSubscriptionController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\Rca\RcaActionItemController;
use App\Http\Controllers\Api\V1\Rca\RcaController;
use App\Http\Controllers\Api\V1\Routing\EscalationPolicyController;
use App\Http\Controllers\Api\V1\Routing\OnCallController;
use App\Http\Controllers\Api\V1\Routing\OnCallScheduleController;
use App\Http\Controllers\Api\V1\Routing\RoutingController;
use App\Http\Controllers\Api\V1\Routing\RoutingRuleController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SlaPolicyController;
use App\Http\Controllers\Api\V1\SprintController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

/*
| Token rules:
| - "abilities:*" routes need a session token from /auth/login. Personal tokens (scripts, MCP)
|   cannot manage accounts, tokens or admin settings, whatever their abilities.
| - "two-factor" routes are blocked for admins and incident managers until TOTP is set up.
| - Incident routes also accept personal tokens with incidents:read, incidents:create or
|   incidents:update. Deleting (and closing, checked in the controller) needs a session.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->middleware('throttle:30,1')->name('health');

    // The signature (from GET /documents/{id}/download, valid 5 minutes) is the authorization.
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])
        ->middleware(['signed', 'throttle:60,1'])
        ->name('documents.file');

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:two-factor')->name('two-factor.challenge');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:login')->name('forgot-password');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:login')->name('reset-password');
        Route::post('accept-invite', [PasswordController::class, 'acceptInvite'])->middleware('throttle:login')->name('accept-invite');
    });

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            Route::middleware('abilities:*')->group(function () {
                Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
                Route::put('password', [PasswordController::class, 'update'])->name('password');

                Route::post('two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
                Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
                Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');

                Route::middleware('two-factor')->group(function () {
                    Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
                    Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
                    Route::delete('tokens/{tokenId}', [TokenController::class, 'destroy'])->whereNumber('tokenId')->name('tokens.destroy');
                });
            });
        });

        Route::middleware('two-factor')->group(function () {
            // Catalog: readable with any token, the reporting form and MCP clients need it.
            Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
            Route::get('people', [PeopleController::class, 'index'])->name('people.index');
            Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show');
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('sla-policies', [SlaPolicyController::class, 'index'])->name('sla-policies.index');

            // Personal notifications: the bell, browser push and preferences (specification 12).
            Route::get('push/config', [PushSubscriptionController::class, 'config'])->name('push.config');
            Route::get('push/subscriptions', [PushSubscriptionController::class, 'index'])->name('push.subscriptions.index');
            Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscriptions.store');
            Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroyByEndpoint'])->name('push.subscriptions.destroy-endpoint');
            Route::delete('push/subscriptions/{pushSubscription}', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');
            Route::post('push/subscriptions/{pushSubscription}/test', [PushSubscriptionController::class, 'test'])->name('push.subscriptions.test');

            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

            Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('me.notification-preferences.show');
            Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('me.notification-preferences.update');

            // Routing reads used by the reporting form and on-call views.
            Route::get('routing/who-to-report', [RoutingController::class, 'whoToReport'])->name('routing.who-to-report');
            Route::get('on-call/now', [OnCallController::class, 'now'])->name('on-call.now');
            Route::get('on-call-schedules', [OnCallScheduleController::class, 'index'])->name('on-call-schedules.index');
            Route::get('on-call-schedules/{onCallSchedule}', [OnCallScheduleController::class, 'show'])->name('on-call-schedules.show');

            Route::middleware('ability:*,incidents:read')->group(function () {
                Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
                Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
                Route::get('incidents/{incident}/updates', [IncidentUpdateController::class, 'index'])->name('incidents.updates.index');
                Route::get('incidents/{incident}/mentionable', [IncidentUpdateController::class, 'mentionable'])->name('incidents.mentionable');
                Route::get('incidents/{incident}/timeline', [IncidentTimelineController::class, 'timeline'])->name('incidents.timeline');
                Route::get('incidents/{incident}/history', [IncidentTimelineController::class, 'history'])->name('incidents.history');
                Route::get('incidents/{incident}/time-entries', [TimeEntryController::class, 'index'])->name('incidents.time-entries.index');
                Route::get('incidents/{incident}/resolution', [IncidentResolutionController::class, 'show'])->name('incidents.resolution.show');

                Route::get('incidents/{incident}/documents', [DocumentController::class, 'index'])->name('incidents.documents.index');
                Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
                Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

                Route::get('knowledge-base', [KnowledgeBaseController::class, 'index'])->name('knowledge-base.index');
                Route::get('knowledge-base/{incident}', [KnowledgeBaseController::class, 'show'])->name('knowledge-base.show');
                Route::get('runbooks', [RunbookController::class, 'index'])->name('runbooks.index');
                Route::get('runbooks/{runbook}', [RunbookController::class, 'show'])->name('runbooks.show');

                Route::get('rca', [RcaController::class, 'index'])->name('rca.index');
                Route::get('rca/{rca}', [RcaController::class, 'show'])->name('rca.show');
                Route::get('incidents/{incident}/rca', [RcaController::class, 'showForIncident'])->name('incidents.rca.show');
                Route::get('rca/{rca}/action-items', [RcaActionItemController::class, 'indexForRca'])->name('rca.action-items.index');
                Route::get('action-items', [RcaActionItemController::class, 'index'])->name('action-items.index');

                Route::get('dashboard/overview', [DashboardController::class, 'overview'])->name('dashboard.overview');
                Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
                Route::get('dashboard/current', [DashboardController::class, 'current'])->name('dashboard.current');
                Route::get('dashboard/trends', [DashboardController::class, 'trends'])->name('dashboard.trends');
                Route::get('dashboard/breakdown', [DashboardController::class, 'breakdown'])->name('dashboard.breakdown');
                Route::get('dashboard/sla', [DashboardController::class, 'sla'])->name('dashboard.sla');
                Route::get('dashboard/workload', [DashboardController::class, 'workload'])->name('dashboard.workload');
                Route::get('dashboard/action-items', [DashboardController::class, 'actionItems'])->name('dashboard.action-items');
            });

            Route::post('incidents', [IncidentController::class, 'store'])
                ->middleware(['ability:*,incidents:create', 'idempotent'])
                ->name('incidents.store');

            Route::middleware('ability:*,incidents:update')->group(function () {
                Route::put('incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
                Route::post('incidents/{incident}/acknowledge', [IncidentWorkflowController::class, 'acknowledge'])->name('incidents.acknowledge');
                Route::post('incidents/{incident}/transition', [IncidentWorkflowController::class, 'transition'])->name('incidents.transition');
                Route::post('incidents/{incident}/reopen', [IncidentWorkflowController::class, 'reopen'])->name('incidents.reopen');
                Route::post('incidents/{incident}/assign', [IncidentWorkflowController::class, 'assign'])->name('incidents.assign');
                Route::put('incidents/{incident}/severity', [IncidentWorkflowController::class, 'severity'])->name('incidents.severity');
                Route::post('incidents/{incident}/escalate', [IncidentWorkflowController::class, 'escalate'])->name('incidents.escalate');

                Route::post('incidents/{incident}/updates', [IncidentUpdateController::class, 'store'])->name('incidents.updates.store');
                Route::put('incident-updates/{incidentUpdate}', [IncidentUpdateController::class, 'update'])->name('incident-updates.update');
                Route::delete('incident-updates/{incidentUpdate}', [IncidentUpdateController::class, 'destroy'])->name('incident-updates.destroy');

                Route::post('incidents/{incident}/time-entries', [TimeEntryController::class, 'store'])->name('incidents.time-entries.store');
                Route::put('time-entries/{timeEntry}', [TimeEntryController::class, 'update'])->name('time-entries.update');
                Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy'])->name('time-entries.destroy');
                Route::post('incidents/{incident}/timer/start', [TimeEntryController::class, 'startTimer'])->name('incidents.timer.start');
                Route::post('incidents/{incident}/timer/stop', [TimeEntryController::class, 'stopTimer'])->name('incidents.timer.stop');

                Route::put('incidents/{incident}/resolution', [IncidentResolutionController::class, 'update'])->name('incidents.resolution.update');
                Route::post('incidents/{incident}/links', [IncidentLinkController::class, 'store'])->name('incidents.links.store');
                Route::post('incidents/{incident}/watchers', [IncidentWatcherController::class, 'store'])->name('incidents.watchers.store');
                Route::delete('incidents/{incident}/watchers', [IncidentWatcherController::class, 'destroy'])->name('incidents.watchers.destroy');

                Route::post('incidents/{incident}/documents', [DocumentController::class, 'store'])->name('incidents.documents.store');
                Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');

                // MCP may draft an RCA and add action items; approving stays human-only (below).
                Route::post('incidents/{incident}/rca', [RcaController::class, 'store'])->name('incidents.rca.store');
                Route::put('rca/{rca}', [RcaController::class, 'update'])->name('rca.update');
                Route::post('rca/{rca}/submit', [RcaController::class, 'submit'])->name('rca.submit');
                Route::post('rca/{rca}/action-items', [RcaActionItemController::class, 'store'])->name('rca.action-items.store');
                Route::put('action-items/{actionItem}', [RcaActionItemController::class, 'update'])->name('action-items.update');
                Route::post('action-items/{actionItem}/complete', [RcaActionItemController::class, 'complete'])->name('action-items.complete');
            });

            Route::middleware('abilities:*')->group(function () {
                // Projects (Work): planned work, sprints and tickets, kept separate from incidents.
                Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
                Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
                Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
                Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
                Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
                Route::post('projects/{project}/members', [ProjectController::class, 'addMember'])->name('projects.members.store');
                Route::delete('projects/{project}/members/{user}', [ProjectController::class, 'removeMember'])->name('projects.members.destroy');

                Route::get('projects/{project}/sprints', [SprintController::class, 'index'])->name('projects.sprints.index');
                Route::post('projects/{project}/sprints', [SprintController::class, 'store'])->name('projects.sprints.store');
                Route::get('sprints/{sprint}', [SprintController::class, 'show'])->name('sprints.show');
                Route::put('sprints/{sprint}', [SprintController::class, 'update'])->name('sprints.update');
                Route::delete('sprints/{sprint}', [SprintController::class, 'destroy'])->name('sprints.destroy');

                Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->name('projects.tasks.index');
                Route::get('projects/{project}/board', [TaskController::class, 'board'])->name('projects.board');
                Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
                Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
                Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
                Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
                Route::post('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
                Route::post('tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
                Route::post('tasks/{task}/request-review', [TaskController::class, 'requestReview'])->name('tasks.request-review');
                Route::post('tasks/{task}/approve', [TaskController::class, 'approve'])->name('tasks.approve');
                Route::post('tasks/{task}/request-changes', [TaskController::class, 'requestChanges'])->name('tasks.request-changes');
                Route::get('tasks/{task}/timeline', [TaskController::class, 'timeline'])->name('tasks.timeline');

                Route::post('teams', [TeamController::class, 'store'])->name('teams.store');
                Route::put('teams/{team}', [TeamController::class, 'update'])->name('teams.update');
                Route::delete('teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
                Route::post('teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
                Route::delete('teams/{team}/members/{user}', [TeamController::class, 'removeMember'])->name('teams.members.destroy');

                Route::post('services', [ServiceController::class, 'store'])->name('services.store');
                Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');

                Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');

                Route::delete('incidents/{incident}', [IncidentController::class, 'destroy'])->name('incidents.destroy');
                Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

                Route::post('rca/{rca}/approve', [RcaController::class, 'approve'])->name('rca.approve');
                Route::post('rca/{rca}/reject', [RcaController::class, 'reject'])->name('rca.reject');
                Route::delete('action-items/{actionItem}', [RcaActionItemController::class, 'destroy'])->name('action-items.destroy');

                Route::post('knowledge-base/{incident}/publish', [KnowledgeBaseController::class, 'publish'])->name('knowledge-base.publish');
                Route::delete('knowledge-base/{incident}/publish', [KnowledgeBaseController::class, 'unpublish'])->name('knowledge-base.unpublish');

                Route::post('runbooks', [RunbookController::class, 'store'])->name('runbooks.store');
                Route::post('runbooks/preview', [RunbookController::class, 'preview'])->name('runbooks.preview');
                Route::put('runbooks/{runbook}', [RunbookController::class, 'update'])->name('runbooks.update');
                Route::delete('runbooks/{runbook}', [RunbookController::class, 'destroy'])->name('runbooks.destroy');

                Route::get('routing-rules', [RoutingRuleController::class, 'index'])->name('routing-rules.index');
                Route::post('routing-rules', [RoutingRuleController::class, 'store'])->name('routing-rules.store');
                Route::post('routing-rules/test', [RoutingController::class, 'simulate'])->name('routing-rules.test');
                Route::put('routing-rules/{routingRule}', [RoutingRuleController::class, 'update'])->name('routing-rules.update');
                Route::delete('routing-rules/{routingRule}', [RoutingRuleController::class, 'destroy'])->name('routing-rules.destroy');

                Route::get('escalation-policies', [EscalationPolicyController::class, 'index'])->name('escalation-policies.index');
                Route::post('escalation-policies', [EscalationPolicyController::class, 'store'])->name('escalation-policies.store');
                Route::get('escalation-policies/{escalationPolicy}', [EscalationPolicyController::class, 'show'])->name('escalation-policies.show');
                Route::put('escalation-policies/{escalationPolicy}', [EscalationPolicyController::class, 'update'])->name('escalation-policies.update');
                Route::delete('escalation-policies/{escalationPolicy}', [EscalationPolicyController::class, 'destroy'])->name('escalation-policies.destroy');

                Route::post('on-call-schedules', [OnCallScheduleController::class, 'store'])->name('on-call-schedules.store');
                Route::put('on-call-schedules/{onCallSchedule}', [OnCallScheduleController::class, 'update'])->name('on-call-schedules.update');
                Route::delete('on-call-schedules/{onCallSchedule}', [OnCallScheduleController::class, 'destroy'])->name('on-call-schedules.destroy');
                Route::post('on-call-schedules/{onCallSchedule}/shifts', [OnCallScheduleController::class, 'storeShifts'])->name('on-call-schedules.shifts.store');
                Route::delete('on-call-schedules/{onCallSchedule}/shifts/{shift}', [OnCallScheduleController::class, 'destroyShift'])->name('on-call-schedules.shifts.destroy');

                Route::prefix('admin')->name('admin.')->group(function () {
                    Route::get('users', [UserController::class, 'index'])->name('users.index');
                    Route::post('users', [UserController::class, 'store'])->name('users.store');
                    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
                    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
                    Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->name('users.roles');
                    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
                    Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
                    Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite'])->name('users.resend-invite');
                    Route::delete('users/{user}/two-factor', [UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset');

                    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
                    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
                    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
                    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
                    Route::get('permissions', PermissionController::class)->name('permissions.index');

                    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
                    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                    Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->name('api-keys.rotate');
                    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

                    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

                    Route::get('notification-log', [NotificationLogController::class, 'index'])->name('notification-log.index');

                    Route::put('sla-policies/{severity}', [SlaPolicyController::class, 'update'])->whereNumber('severity')->name('sla-policies.update');

                    Route::get('settings', [SettingController::class, 'show'])->name('settings.show');
                    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
                });
            });
        });
    });
});
