<?php

use Illuminate\Support\Facades\Storage;

it('reports every dependency as ok without authentication', function () {
    Storage::fake('local');

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok'],
        ]);
});
