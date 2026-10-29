<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a fresh Web Push key pair. Add both lines to .env and restart the queue workers.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'incidents:vapid-keys';

    protected $description = 'Generate a VAPID key pair for browser push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to .env, then restart the queue workers:');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->newLine();
        $this->comment('Changing the keys invalidates every existing subscription; people turn notifications on again.');

        return self::SUCCESS;
    }
}
