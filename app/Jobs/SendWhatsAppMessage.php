<?php

namespace App\Jobs;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationLog;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one WhatsApp text message through the WAPI gateway. The delivery log row is created
 * before the job is queued; this job moves it to sent or failed.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $number,
        public string $text,
        public ?int $logId = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(WhatsAppClient $client): void
    {
        $client->sendText($this->number, $this->text);

        $this->log()?->forceFill([
            'status' => NotificationDeliveryStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ])->saveQuietly();
    }

    public function failed(?Throwable $error): void
    {
        $this->log()?->forceFill([
            'status' => NotificationDeliveryStatus::Failed,
            'error' => mb_substr($error?->getMessage() ?? 'The message could not be sent.', 0, 1000),
        ])->saveQuietly();
    }

    private function log(): ?NotificationLog
    {
        return $this->logId === null ? null : NotificationLog::query()->find($this->logId);
    }
}
