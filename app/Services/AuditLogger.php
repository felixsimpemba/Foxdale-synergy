<?php

namespace App\Services;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Writes rows to the append-only audit log. Request details (source, IP, user agent,
 * API key) come from the Context set by the AuditContext middleware, so the same call
 * works from controllers, jobs, MCP tools and console commands.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function record(
        string $action,
        ?Model $entity = null,
        ?array $changes = null,
        ?User $actor = null,
        ?AuditSource $source = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $token = $actor?->currentAccessToken();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'api_key_id' => Context::get('audit.api_key_id'),
            'token_id' => $token instanceof PersonalAccessToken && $token->exists ? $token->getKey() : null,
            'source' => ($source ?? $this->currentSource())->value,
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'changes' => $changes,
            'ip' => Context::getHidden('audit.ip'),
            'user_agent' => Str::limit((string) Context::getHidden('audit.user_agent'), 500, '') ?: null,
        ]);
    }

    private function currentSource(): AuditSource
    {
        return AuditSource::tryFrom((string) Context::get('audit.source')) ?? AuditSource::System;
    }
}
