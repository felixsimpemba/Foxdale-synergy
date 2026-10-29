<?php

use Illuminate\Support\Facades\Artisan;

it('prints a VAPID key pair for the environment file', function () {
    $exit = Artisan::call('incidents:vapid-keys');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{80,}/')
        ->and($output)->toMatch('/VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{40,}/');
});
