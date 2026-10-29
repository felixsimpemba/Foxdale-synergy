<?php

namespace App\Providers;

use App\Events\IncidentAssigned;
use App\Events\IncidentStatusChanged;
use App\Events\SlaBreached;
use App\Events\SlaWarningRaised;
use App\Listeners\NotifyOnIncidentAssigned;
use App\Listeners\NotifyOnSlaBreach;
use App\Listeners\NotifyOnSlaWarning;
use App\Listeners\NotifyOnStatusChanged;
use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Document;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\IncidentLink;
use App\Models\IncidentResolution;
use App\Models\IncidentSlaEvent;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\RoutingRule;
use App\Models\Runbook;
use App\Models\Service;
use App\Models\Setting;
use App\Models\SlaPolicy;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\Dashboard\DashboardCache;
use App\Services\Notifications\PushSender;
use App\Services\Notifications\WebPushSender;
use App\Services\Scanning\NullVirusScanner;
use App\Services\Scanning\VirusScanner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Only "none" exists today; bind a ClamAV scanner here when INCIDENTS_VIRUS_SCANNER=clamav.
        $this->app->bind(VirusScanner::class, NullVirusScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Not shouldBeStrict(): freshly created models lack unset columns until refreshed,
        // which would make preventAccessingMissingAttributes() throw in resources.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Short, stable names in audit_logs.entity_type and polymorphic columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'team' => Team::class,
            'service' => Service::class,
            'category' => Category::class,
            'api_key' => ApiKey::class,
            'setting' => Setting::class,
            'incident' => Incident::class,
            'incident_link' => IncidentLink::class,
            'incident_resolution' => IncidentResolution::class,
            'time_entry' => TimeEntry::class,
            'sla_policy' => SlaPolicy::class,
            'routing_rule' => RoutingRule::class,
            'escalation_policy' => EscalationPolicy::class,
            'on_call_schedule' => OnCallSchedule::class,
            'on_call_shift' => OnCallShift::class,
            'document' => Document::class,
            'runbook' => Runbook::class,
            'rca' => Rca::class,
            'rca_action_item' => RcaActionItem::class,
            'role' => Role::class,
            'permission' => Permission::class,
        ]);

        Gate::policy(Role::class, RolePolicy::class);

        $this->app->bind(PushSender::class, fn (): WebPushSender => new WebPushSender(
            subject: config('incidents.push.vapid_subject'),
            publicKey: config('incidents.push.vapid_public_key'),
            privateKey: config('incidents.push.vapid_private_key'),
        ));

        $this->registerNotificationListeners();

        Password::defaults(fn () => Password::min(12)->uncompromised());

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => config('incidents.frontend_url')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));

        $this->configureRateLimiting();
        $this->bustDashboardCacheOnChanges();
    }

    /**
     * Cached dashboard figures are recomputed after any change they are built from.
     */
    /**
     * Everything that tells someone about an incident or RCA goes through NotificationService.
     */
    private function registerNotificationListeners(): void
    {
        Event::listen(IncidentAssigned::class, NotifyOnIncidentAssigned::class);
        Event::listen(IncidentStatusChanged::class, NotifyOnStatusChanged::class);
        Event::listen(SlaWarningRaised::class, NotifyOnSlaWarning::class);
        Event::listen(SlaBreached::class, NotifyOnSlaBreach::class);
    }

    private function bustDashboardCacheOnChanges(): void
    {
        $bust = fn () => app(DashboardCache::class)->bust();

        foreach ([Incident::class, TimeEntry::class, IncidentSlaEvent::class, Rca::class, RcaActionItem::class] as $model) {
            $model::saved($bust);
            $model::deleted($bust);
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by((string) $request->input('challenge_token').'|'.$request->ip()));
    }
}
