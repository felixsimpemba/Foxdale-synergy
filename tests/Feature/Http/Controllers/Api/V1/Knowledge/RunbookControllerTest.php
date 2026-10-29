<?php

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Runbook;
use App\Models\Service;

it('lets a responder write a runbook and renders its Markdown safely', function () {
    $responder = actingAsRole(RoleName::Responder);
    $service = Service::factory()->create();

    $response = $this->postJson('/api/v1/runbooks', [
        'title' => 'Drain the callback queue',
        'body' => "## Steps\n\n1. Restart the consumer\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
        'service_id' => $service->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.service_id', $service->id)
        ->assertJsonPath('data.created_by', $responder->id);

    expect($response->json('data.body_html'))
        ->toContain('<h2>Steps</h2>')
        ->not->toContain('<script>')
        ->not->toContain('javascript:');
});

it('filters runbooks by service and title', function () {
    actingAsRole(RoleName::Reporter);
    $service = Service::factory()->create();
    $match = Runbook::factory()->create(['title' => 'Restart RabbitMQ consumer', 'service_id' => $service->id]);
    Runbook::factory()->create(['title' => 'Restart RabbitMQ node']);
    Runbook::factory()->create(['title' => 'Rotate TLS certificate', 'service_id' => $service->id]);

    $this->getJson("/api/v1/runbooks?service_id={$service->id}&q=rabbitmq")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('searches the body as well as the title', function () {
    actingAsRole(RoleName::Reporter);
    $match = Runbook::factory()->create(['title' => 'Callback backlog', 'body' => 'Restart the RabbitMQ consumer']);
    Runbook::factory()->create(['title' => 'Rotate TLS certificate', 'body' => 'Renew with certbot']);

    $this->getJson('/api/v1/runbooks?q=rabbitmq')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('matches the service or the category with match=any, and names both', function () {
    $author = actingAsRole(RoleName::Reporter);
    $service = Service::factory()->create(['name' => 'Payment gateway']);
    $category = Category::factory()->create(['name' => 'Database']);
    $forService = Runbook::factory()->create(['service_id' => $service->id, 'created_by' => $author->id]);
    $forCategory = Runbook::factory()->create(['category_id' => $category->id]);
    Runbook::factory()->create();

    $response = $this->getJson("/api/v1/runbooks?service_id={$service->id}&category_id={$category->id}&match=any")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$forService->id]['service'])->toBe(['id' => $service->id, 'name' => 'Payment gateway'])
        ->and($byId[$forService->id]['creator'])->toBe(['id' => $author->id, 'name' => $author->name])
        ->and($byId[$forCategory->id]['category'])->toBe(['id' => $category->id, 'name' => 'Database']);

    $this->getJson("/api/v1/runbooks?service_id={$service->id}&category_id={$category->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('previews Markdown with the same sanitising as saved runbooks', function () {
    actingAsRole(RoleName::Responder);

    $html = $this->postJson('/api/v1/runbooks/preview', ['body' => "# Title\n\n<script>x</script>"])
        ->assertOk()
        ->json('data.body_html');

    expect($html)->toContain('<h1>Title</h1>')->not->toContain('<script>');
});

it('returns 403 when a reporter previews a runbook', function () {
    actingAsRole(RoleName::Reporter);

    $this->postJson('/api/v1/runbooks/preview', ['body' => 'x'])->assertForbidden();
});

it('returns 403 when a reporter writes a runbook', function () {
    actingAsRole(RoleName::Reporter);

    $this->postJson('/api/v1/runbooks', ['title' => 'x', 'body' => 'y'])->assertForbidden();
});

it('updates a runbook and records the editor', function () {
    $responder = actingAsRole(RoleName::Responder);
    $runbook = Runbook::factory()->create();

    $this->putJson("/api/v1/runbooks/{$runbook->id}", ['title' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated')
        ->assertJsonPath('data.updated_by', $responder->id);
});

it('lets only incident managers and admins delete runbooks', function () {
    $runbook = Runbook::factory()->create();

    actingAsRole(RoleName::Responder);
    $this->deleteJson("/api/v1/runbooks/{$runbook->id}")->assertForbidden();

    actingAsRole(RoleName::IncidentManager);
    $this->deleteJson("/api/v1/runbooks/{$runbook->id}")->assertNoContent();

    $this->assertSoftDeleted($runbook);
});
