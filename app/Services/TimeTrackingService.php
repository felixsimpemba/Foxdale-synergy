<?php

namespace App\Services;

use App\Enums\TimeEntrySource;
use App\Exceptions\ApiException;
use App\Models\Incident;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manual time entries and the live start/stop work timer.
 */
class TimeTrackingService
{
    /**
     * @param  array{minutes: int, note?: string|null, worked_on?: string|null}  $data
     */
    public function log(Incident $incident, User $user, array $data): TimeEntry
    {
        return $incident->timeEntries()->create([
            'user_id' => $user->id,
            'minutes' => $data['minutes'],
            'note' => $data['note'] ?? null,
            'worked_on' => $data['worked_on'] ?? today(),
            'source' => TimeEntrySource::Manual,
        ]);
    }

    public function startTimer(Incident $incident, User $user): TimeEntry
    {
        if (! $incident->status->isOpen()) {
            throw new ApiException('A timer can only run on an open incident.', 'incident_not_open');
        }

        return DB::transaction(function () use ($incident, $user): TimeEntry {
            $running = $incident->timeEntries()->running()->where('user_id', $user->id)->lockForUpdate()->exists();

            if ($running) {
                throw new ApiException('You already have a timer running on this incident.', 'timer_running');
            }

            return $incident->timeEntries()->create([
                'user_id' => $user->id,
                'worked_on' => today(),
                'started_at' => now(),
                'source' => TimeEntrySource::Timer,
            ]);
        });
    }

    public function stopTimer(Incident $incident, User $user): TimeEntry
    {
        $entry = $incident->timeEntries()->running()->where('user_id', $user->id)->first();

        if ($entry === null) {
            throw new ApiException('You have no timer running on this incident.', 'no_running_timer');
        }

        $this->finish($entry, now());

        return $entry;
    }

    /**
     * Stop every running timer on the incident, e.g. when it is resolved.
     */
    public function stopRunningTimers(Incident $incident): void
    {
        $now = now();

        $incident->timeEntries()->running()->get()->each(fn (TimeEntry $entry) => $this->finish($entry, $now));
    }

    private function finish(TimeEntry $entry, Carbon $endedAt): void
    {
        // Whole seconds: Carbon returns fractions, and the stored start time has none.
        $seconds = (int) $entry->started_at->diffInSeconds($endedAt);

        $entry->update([
            'ended_at' => $endedAt,
            'minutes' => max(1, (int) ceil($seconds / 60)),
        ]);
    }
}
