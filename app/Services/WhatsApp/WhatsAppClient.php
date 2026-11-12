<?php

namespace App\Services\WhatsApp;

use App\Exceptions\ApiException;
use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the self-hosted WAPI gateway (an Evolution-style WhatsApp API). Every
 * call is an HTTP request against /{instance}/... authenticated with the global api key.
 */
class WhatsAppClient
{
    public function __construct(private readonly SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return filled(config('incidents.whatsapp.api_key'));
    }

    /** The master switch admins control from the WhatsApp admin page. */
    public function isEnabled(): bool
    {
        return $this->isConfigured() && $this->settings->get('channels.whatsapp.enabled') === true;
    }

    public function instance(): string
    {
        $instance = $this->settings->get('whatsapp.instance');

        return is_string($instance) && $instance !== ''
            ? $instance
            : (string) config('incidents.whatsapp.instance');
    }

    /**
     * Send a plain text message to a phone number (digits, e.g. 260973419876) or a group
     * JID (e.g. 120363410040073098@g.us).
     *
     * @return array<string, mixed>
     */
    public function sendText(string $number, string $text): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->post(
            '/message/sendText/'.$this->instancePath(),
            ['number' => $number, 'text' => $text],
        ));
    }

    /**
     * Groups on the linked WhatsApp account.
     *
     * @return list<array{group_jid: string, subject: string, size: int|null}>
     */
    public function groups(): array
    {
        $payload = $this->call(fn (PendingRequest $http): Response => $http->get(
            '/group/fetchAllGroups/'.$this->instancePath(),
            ['getParticipants' => 'false'],
        ));

        $wrapped = data_get($payload, 'data') ?? data_get($payload, 'groups');
        $groups = is_array($wrapped) ? $wrapped : $payload;

        if (! is_array($groups)) {
            return [];
        }

        $result = [];

        foreach ($groups as $group) {
            if (! is_array($group) || ! filled($group['id'] ?? null)) {
                continue;
            }

            $result[] = [
                'group_jid' => (string) $group['id'],
                'subject' => (string) ($group['subject'] ?? $group['id']),
                'size' => isset($group['size']) ? (int) $group['size'] : null,
            ];
        }

        return $result;
    }

    /**
     * The instance's connection state: "open" means linked and ready.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->get(
            '/instance/connectionState/'.$this->instancePath(),
        ));
    }

    public function stateValue(): ?string
    {
        $payload = $this->state();
        $state = data_get($payload, 'instance.state') ?? data_get($payload, 'state');

        return is_string($state) ? $state : null;
    }

    /**
     * Ask the gateway for a QR code that links a phone to the instance.
     *
     * @return array{base64: string|null, code: string|null}
     */
    public function connect(): array
    {
        $payload = $this->call(fn (PendingRequest $http): Response => $http->get(
            '/instance/connect/'.$this->instancePath(),
        ));

        $base64 = data_get($payload, 'base64')
            ?? data_get($payload, 'qrcode.base64')
            ?? data_get($payload, 'value');
        $code = data_get($payload, 'code') ?? data_get($payload, 'qrcode.code');

        return [
            'base64' => is_string($base64) ? $base64 : null,
            'code' => is_string($code) ? $code : null,
        ];
    }

    /**
     * Create a new QR-linked (Baileys) instance on the gateway.
     *
     * @return array<string, mixed>
     */
    public function createInstance(string $instance): array
    {
        return $this->call(fn (PendingRequest $http): Response => $http->post('/instance/create', [
            'instanceName' => $instance,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ]));
    }

    private function instancePath(): string
    {
        return rawurlencode($this->instance());
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('incidents.whatsapp.base_url'))
            ->withHeaders(['apikey' => (string) config('incidents.whatsapp.api_key')])
            ->acceptJson()
            ->timeout((int) config('incidents.whatsapp.timeout'));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function call(callable $send): array
    {
        if (! $this->isConfigured()) {
            throw new ApiException(
                'WhatsApp is not configured. Set WHATSAPP_API_KEY on the server.',
                'whatsapp_not_configured',
            );
        }

        try {
            $response = $send($this->http());
        } catch (ConnectionException $error) {
            throw new ApiException(
                'Cannot reach the WhatsApp gateway: '.$error->getMessage(),
                'whatsapp_unreachable',
                502,
            );
        }

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('error');

            throw new ApiException(
                'The WhatsApp gateway rejected the request'.(is_string($message) ? ": {$message}" : '.'),
                'whatsapp_error',
                502,
            );
        }

        $payload = $response->json();

        if (is_array($payload)) {
            return $payload;
        }

        // Some gateway endpoints answer with a bare string (for example the QR image).
        $body = trim($response->body());

        return $body === '' ? [] : ['value' => $payload ?? $body];
    }
}
