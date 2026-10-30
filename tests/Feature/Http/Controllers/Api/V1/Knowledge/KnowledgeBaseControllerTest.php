<?php

use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Incident;
use App\Models\IncidentResolution;

// Search ranking needs committed FULLTEXT indexes and is covered in tests/Integration.

function resolvedIncidentWithFix(array $resolution = [], array $incident = []): Incident
{
    $resolved = Incident::factory()->inStatus(IncidentStatus::Resolved)->create($incident);
    IncidentResolution::factory()->for($resolved)->create($resolution);

    return $resolved;
}

describe('show', function () {
    it('shows a published entry to a reporter, without the incident documents', function () {
        actingAsRole(RoleName::Reporter);
        $incident = resolvedIncidentWithFix(['summary' => 'Consumer reconnect', 'is_published' => true]);
        Document::factory()->for($incident)->create();

        $this->getJson("/api/v1/knowledge-base/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('data.resolution.summary', 'Consumer reconnect')
            ->assertJsonPath('data.is_published', true)
            ->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.incident.description');
    });

    it('includes documents for someone who can see the incident', function () {
        actingAsRole(RoleName::Noc);
        $incident = resolvedIncidentWithFix();
        Document::factory()->for($incident)->create();

        $this->getJson("/api/v1/knowledge-base/{$incident->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.documents');
    });

    it('returns 404 for an unpublished entry the user cannot see', function () {
        actingAsRole(RoleName::Reporter);
        $incident = resolvedIncidentWithFix();

        $this->getJson("/api/v1/knowledge-base/{$incident->id}")->assertNotFound();
    });

    it('returns 404 for an incident that is still open', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Investigating)->create();
        IncidentResolution::factory()->for($incident)->create();

        $this->getJson("/api/v1/knowledge-base/{$incident->id}")->assertNotFound();
    });
});

describe('publish', function () {
    it('publishes and unpublishes a resolution', function () {
        $manager = actingAsRole(RoleName::IncidentManager);
        $incident = resolvedIncidentWithFix();

        $this->postJson("/api/v1/knowledge-base/{$incident->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.published_by', ['id' => $manager->id, 'name' => $manager->name]);

        expect($incident->resolution->fresh())->published_by->toBe($manager->id);

        $this->deleteJson("/api/v1/knowledge-base/{$incident->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.published_by', null);
    });

    it('returns 422 not_resolved for an open incident', function () {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/knowledge-base/{$incident->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_resolved');
    });

    it('returns 403 for NOC', function () {
        actingAsRole(RoleName::Noc);
        $incident = resolvedIncidentWithFix();

        $this->postJson("/api/v1/knowledge-base/{$incident->id}/publish")->assertForbidden();
    });
});
