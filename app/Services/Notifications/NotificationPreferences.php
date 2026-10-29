<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * What each user wants to be told about, and when to stay quiet. Defaults come from
 * NotificationEvent; stored settings override them. Quiet hours mute browser push only
 * (never the bell or email), and critical events ignore them.
 */
class NotificationPreferences
{
    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{channels: array<string, bool>, quiet_hours: array{enabled: bool, start: string, end: string}}
     */
    public function defaults(): array
    {
        return [
            'channels' => [
                NotificationChannel::InApp->value => true,
                NotificationChannel::Push->value => true,
                NotificationChannel::Email->value => true,
            ],
            'quiet_hours' => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
        ];
    }

    /**
     * The settings as the user should see them: every event with the channels it currently
     * arrives on, after global channel switches and quiet hours.
     *
     * @return array{channels: array<string, bool>, quiet_hours: array{enabled: bool, start: string, end: string, timezone: string}, events: array<string, list<string>>}
     */
    public function for(User $user): array
    {
        $settings = $this->stored($user);
        $channels = $settings['channels'];
        $quiet = $settings['quiet_hours'];
        $events = [];

        foreach (NotificationEvent::cases() as $event) {
            $events[$event->value] = array_map(
                fn (NotificationChannel $channel): string => $channel->value,
                $this->effectiveChannels($user, $event, $settings),
            );
        }

        return [
            'channels' => $channels,
            'quiet_hours' => [...$quiet, 'timezone' => $user->timezone],
            'events' => $events,
        ];
    }

    /**
     * @param  array{channels?: array<string, bool>, events?: array<string, list<string>>, quiet_hours?: array{enabled?: bool, start?: string, end?: string}}  $input
     * @return array{channels: array<string, bool>, quiet_hours: array{enabled: bool, start: string, end: string, timezone: string}, events: array<string, list<string>>}
     */
    public function store(User $user, array $input): array
    {
        $defaults = $this->defaults();
        $knownEvents = array_column(NotificationEvent::cases(), 'value');
        $knownChannels = array_column(NotificationChannel::cases(), 'value');

        $events = collect($input['events'] ?? [])
            ->filter(fn (array $channels, string $event): bool => in_array($event, $knownEvents, true))
            ->map(fn (array $channels): array => array_values(array_intersect($channels, $knownChannels)))
            ->all();

        $settings = [
            'channels' => array_merge($defaults['channels'], $input['channels'] ?? []),
            'events' => $events,
            'quiet_hours' => array_merge($defaults['quiet_hours'], $input['quiet_hours'] ?? []),
        ];

        NotificationPreference::query()->updateOrCreate(['user_id' => $user->id], ['settings' => $settings]);
        unset($this->cache[$user->id]);

        $this->audit->record('notification_preferences.updated', $user, ['after' => $settings], $user);

        return $this->for($user);
    }

    /**
     * Channels the event will actually arrive on right now.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(User $user, NotificationEvent $event): array
    {
        $channels = $this->effectiveChannels($user, $event, $this->stored($user));

        if ($this->inQuietHours($user) && ! $event->isCritical()) {
            $channels = array_values(array_filter(
                $channels,
                fn (NotificationChannel $channel): bool => $channel !== NotificationChannel::Push,
            ));
        }

        return $channels;
    }

    public function inQuietHours(User $user): bool
    {
        $quiet = $this->stored($user)['quiet_hours'];

        if (! $quiet['enabled']) {
            return false;
        }

        $now = now($user->timezone ?: config('incidents.display_timezone'))->format('H:i');
        [$start, $end] = [$quiet['start'], $quiet['end']];

        return $start <= $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    /**
     * @param  array{channels: array<string, bool>, quiet_hours: array<string, mixed>, events: array<string, list<string>>}  $settings
     * @return list<NotificationChannel>
     */
    private function effectiveChannels(User $user, NotificationEvent $event, array $settings): array
    {
        $wanted = $settings['events'][$event->value]
            ?? array_map(fn (NotificationChannel $channel): string => $channel->value, $event->defaultChannels());

        return collect($wanted)
            ->filter(fn (string $channel): bool => $settings['channels'][$channel] ?? false)
            ->map(fn (string $channel): NotificationChannel => NotificationChannel::from($channel))
            ->values()
            ->all();
    }

    /**
     * @return array{channels: array<string, bool>, quiet_hours: array{enabled: bool, start: string, end: string}, events: array<string, list<string>>}
     */
    private function stored(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $defaults = $this->defaults();
        $preference = NotificationPreference::query()->where('user_id', $user->id)->first();
        /** @var array<string, mixed> $saved */
        $saved = $preference === null ? [] : $preference->settings;

        return $this->cache[$user->id] = [
            'channels' => array_merge($defaults['channels'], is_array($saved['channels'] ?? null) ? $saved['channels'] : []),
            'quiet_hours' => array_merge($defaults['quiet_hours'], is_array($saved['quiet_hours'] ?? null) ? $saved['quiet_hours'] : []),
            'events' => is_array($saved['events'] ?? null) ? $saved['events'] : [],
        ];
    }
}
