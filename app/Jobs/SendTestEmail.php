<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Queued test mail: proves the worker can reach the mail server. Run with
 * incidents:test-mail --queue and watch the worker log.
 */
class SendTestEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $recipient)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        try {
            Mail::raw('Queued test message from Foxdale Synergy.', fn ($message) => $message
                ->to($this->recipient)
                ->subject('Foxdale Synergy queued mail test'));

            Log::info('SendTestEmail: delivered', ['to' => $this->recipient]);
        } catch (Throwable $error) {
            Log::error('SendTestEmail failed', ['to' => $this->recipient, 'error' => $error->getMessage()]);

            throw $error;
        }
    }
}
