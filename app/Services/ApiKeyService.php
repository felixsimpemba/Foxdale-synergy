<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates and rotates monitoring API keys. The plain key and signing secret are returned
 * once and never stored; only the SHA-256 hash of the key is kept.
 */
class ApiKeyService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, source: string, scopes: list<string>, ip_allowlist?: list<string>|null, with_signing_secret?: bool}  $data
     * @return array{api_key: ApiKey, plain_key: string, signing_secret: string|null}
     */
    public function create(array $data, User $creator): array
    {
        $plainKey = $this->generatePlainKey();
        $signingSecret = ($data['with_signing_secret'] ?? false) ? Str::random(48) : null;

        $apiKey = ApiKey::create([
            'name' => $data['name'],
            'source' => $data['source'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'ip_allowlist' => $data['ip_allowlist'] ?? null,
            'key_prefix' => $this->prefixOf($plainKey),
            'key_hash' => ApiKey::hashKey($plainKey),
            'signing_secret' => $signingSecret,
            'created_by' => $creator->id,
        ]);

        return ['api_key' => $apiKey, 'plain_key' => $plainKey, 'signing_secret' => $signingSecret];
    }

    /**
     * Replace the key (and signing secret, if the key has one). The old key stops working at once.
     *
     * @return array{api_key: ApiKey, plain_key: string, signing_secret: string|null}
     */
    public function rotate(ApiKey $apiKey): array
    {
        $plainKey = $this->generatePlainKey();
        $signingSecret = $apiKey->signing_secret !== null ? Str::random(48) : null;

        $apiKey->forceFill([
            'key_prefix' => $this->prefixOf($plainKey),
            'key_hash' => ApiKey::hashKey($plainKey),
            'signing_secret' => $signingSecret,
        ])->save();

        $this->audit->record('api_key.rotated', $apiKey);

        return ['api_key' => $apiKey, 'plain_key' => $plainKey, 'signing_secret' => $signingSecret];
    }

    public function revoke(ApiKey $apiKey): void
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();
    }

    private function generatePlainKey(): string
    {
        return 'fxk_'.Str::random(48);
    }

    private function prefixOf(string $plainKey): string
    {
        return substr($plainKey, 0, 12);
    }
}
