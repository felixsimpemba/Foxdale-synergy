<?php

use App\Jobs\SendTestEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('sends a test message straight away and prints the settings in use', function () {
    Mail::fake();

    expect(Artisan::call('incidents:test-mail', ['to' => 'admin@example.com']))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('admin@example.com')
        ->and($output)->toContain('Host')
        ->and($output)->toContain('From address');
});

it('can send the test through the queue worker instead', function () {
    Queue::fake();

    expect(Artisan::call('incidents:test-mail', ['to' => 'admin@example.com', '--queue' => true]))->toBe(0);

    Queue::assertPushedOn('notifications', SendTestEmail::class, fn (SendTestEmail $job): bool => $job->recipient === 'admin@example.com');
});

it('rejects an invalid address', function () {
    expect(Artisan::call('incidents:test-mail', ['to' => 'nope']))->not->toBe(0);
});

it('reports the mail server error when sending fails', function () {
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Connection could not be established with host "smtp.example.com:25"'));

    expect(Artisan::call('incidents:test-mail', ['to' => 'admin@example.com']))->not->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('The mail server refused the message')
        ->and($output)->toContain('block outbound port 25');
});
