<?php

namespace App\Console\Commands;

use App\Jobs\SendTestEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Checks the mail setup from the server itself: prints the settings actually in use (not
 * just what .env says), then sends a real message and reports the SMTP error, if any.
 *
 * Run it as the same user as the queue worker, e.g.
 * sudo -u www-data php artisan incidents:test-mail you@example.com
 */
class TestMail extends Command
{
    protected $signature = 'incidents:test-mail
        {to : Address to send the test message to}
        {--queue : Send through the queue worker instead of immediately}';

    protected $description = 'Send a test email and report exactly what the mail server says';

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $smtp = (array) config("mail.mailers.{$mailer}", []);
        $from = (string) config('mail.from.address');
        $scheme = $smtp['scheme'] ?? null;

        $this->table(['Setting', 'Value in use right now'], [
            ['Mailer', $mailer],
            ['Transport', (string) ($smtp['transport'] ?? 'n/a')],
            ['Host', (string) ($smtp['host'] ?? 'n/a')],
            ['Port', (string) ($smtp['port'] ?? 'n/a')],
            ['Scheme', $scheme === null || $scheme === '' ? '(auto: smtps on 465, otherwise smtp with STARTTLS)' : (string) $scheme],
            ['Username', empty($smtp['username']) ? '(none)' : '(set)'],
            ['Password', empty($smtp['password']) ? '(none)' : '(set)'],
            ['From address', $from],
            ['Queue connection', (string) config('queue.default')],
        ]);

        if (app()->configurationIsCached()) {
            $this->warn('Configuration is cached: .env changes are ignored until you run `php artisan optimize:clear && php artisan optimize`.');
        }

        if ($this->option('queue')) {
            SendTestEmail::dispatch($to);

            $this->info("Queued a test message to {$to} on the notifications queue.");
            $this->line('Watch it run: tail -f /var/log/supervisor/foxdale-synergy-worker.log');
            $this->line('If it fails: php artisan queue:failed');

            return self::SUCCESS;
        }

        try {
            Mail::raw('Test message from Foxdale Synergy.', fn ($message) => $message
                ->to($to)
                ->subject('Foxdale Synergy mail test'));

            $this->info("Sent. Check {$to}; if nothing arrives, look in spam and at the mail provider's logs.");

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('The mail server refused the message:');
            $this->line($error->getMessage());

            $message = $error->getMessage();

            if (str_contains($message, 'Connection could not be established') || str_contains($message, 'Connection refused')) {
                $this->newLine();
                $this->line('Cannot reach the SMTP host from this server. Most VPS providers block outbound port 25:');
                $this->line('  nc -vz '.($smtp['host'] ?? 'HOST').' '.($smtp['port'] ?? 'PORT'));
                $this->line('Use your provider\'s relay on port 587 with a username and password (MAIL_SCHEME=smtp).');
            }

            if (str_contains($message, 'authentication') || str_contains($message, '535') || str_contains($message, '530')) {
                $this->newLine();
                $this->line('The server wants authentication: set MAIL_USERNAME and MAIL_PASSWORD for the mailbox or SMTP key.');
            }

            if (str_contains($message, 'Sender address rejected') || str_contains($message, 'sender') || str_contains($message, '550')) {
                $this->newLine();
                $this->line("The sender {$from} is not accepted: verify that address (or its domain) with your mail provider.");
            }

            if (str_contains($message, 'certificate') || str_contains($message, 'SSL')) {
                $this->newLine();
                $this->line('TLS problem: port 465 needs MAIL_SCHEME=smtps; port 587 with STARTTLS needs MAIL_SCHEME=smtp.');
            }

            return self::FAILURE;
        }
    }
}
