<?php

use App\Enums\RoleName;
use App\Models\Category;

it('lists categories alphabetically', function () {
    actingAsRole(RoleName::Reporter);
    Category::factory()->create(['name' => 'Network']);
    Category::factory()->create(['name' => 'Database']);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Database')
        ->assertJsonPath('data.1.name', 'Network');
});

it('creates a sub-category', function () {
    actingAsRole(RoleName::Admin);
    $parent = Category::factory()->create(['name' => 'Database']);

    $this->postJson('/api/v1/categories', ['name' => 'Replication', 'parent_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $parent->id);
});

it('rejects a duplicate name under the same parent', function () {
    actingAsRole(RoleName::Admin);
    $parent = Category::factory()->create();
    Category::factory()->create(['name' => 'Replication', 'parent_id' => $parent->id]);

    $this->postJson('/api/v1/categories', ['name' => 'Replication', 'parent_id' => $parent->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('returns 403 for a reporter', function () {
    actingAsRole(RoleName::Reporter);

    $this->postJson('/api/v1/categories', ['name' => 'Payments'])->assertForbidden();
});
