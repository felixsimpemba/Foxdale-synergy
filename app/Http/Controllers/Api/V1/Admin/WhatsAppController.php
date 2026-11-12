<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Services\Notifications\WhatsAppNotifier;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin control of the WhatsApp gateway: connection state, QR linking, group selection and
 * a test message. Sending itself happens in WhatsAppNotifier.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly SettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->status()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'instance' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $values = [];

        if (array_key_exists('enabled', $data)) {
            $values['channels.whatsapp.enabled'] = $data['enabled'];
        }

        if (array_key_exists('instance', $data)) {
            $values['whatsapp.instance'] = (string) $data['instance'];
        }

        if ($values !== []) {
            $this->settings->update($values);
        }

        return response()->json(['data' => $this->status()]);
    }

    /**
     * Fetch the groups on the linked account and merge them with what the admin saved. New
     * groups start switched off.
     */
    public function groups(): JsonResponse
    {
        $synced = false;
        $error = null;

        if ($this->client->isConfigured()) {
            try {
                $groups = $this->client->groups();
                $seen = [];

                foreach ($groups as $group) {
                    $seen[] = $group['group_jid'];
                    WhatsAppGroup::query()->updateOrCreate(
                        ['group_jid' => $group['group_jid']],
                        ['subject' => $group['subject']],
                    );
                }

                // Groups that no longer exist on the account must stop receiving alerts.
                WhatsAppGroup::query()->whereNotIn('group_jid', $seen)->update(['is_active' => false]);

                $synced = true;
            } catch (ApiException $failure) {
                $error = $failure->getMessage();
            }
        }

        return response()->json(['data' => [
            'synced' => $synced,
            'error' => $error,
            'groups' => $this->groupPayload(),
        ]]);
    }

    public function saveGroups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['present', 'array'],
            'groups.*.group_jid' => ['required', 'string', 'max:64', 'regex:/^[0-9-]+@g\.us$/'],
            'groups.*.subject' => ['nullable', 'string', 'max:255'],
            'groups.*.is_active' => ['required', 'boolean'],
        ]);

        foreach ($data['groups'] as $group) {
            WhatsAppGroup::query()->updateOrCreate(
                ['group_jid' => $group['group_jid']],
                [
                    'subject' => $group['subject'] ?? $group['group_jid'],
                    'is_active' => $group['is_active'],
                ],
            );
        }

        return response()->json(['data' => ['groups' => $this->groupPayload()]]);
    }

    /**
     * Create a new QR-linked instance and make it the active one.
     */
    public function createInstance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $instance = (string) $data['instance'];
        $payload = $this->client->createInstance($instance);
        $this->settings->update(['whatsapp.instance' => $instance]);

        return response()->json(['data' => [
            'instance' => $data['instance'],
            'response' => $payload,
        ]], 201);
    }

    /**
     * The base64 QR code for linking a phone to the active instance.
     */
    public function connect(): JsonResponse
    {
        return response()->json(['data' => $this->client->connect()]);
    }

    /**
     * Send a test message synchronously, to a number or to a group.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required_without:group_jid', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s()-]{5,30}$/'],
            'group_jid' => ['required_without:number', 'nullable', 'string', 'max:64', 'regex:/^[0-9-]+@g\.us$/'],
            'text' => ['nullable', 'string', 'max:1000'],
        ]);

        $target = filled($data['number'] ?? null)
            ? WhatsAppNotifier::normalizeNumber((string) $data['number'])
            : (string) $data['group_jid'];

        $this->client->sendText(
            $target,
            (string) ($data['text'] ?? 'Foxdale test message: WhatsApp is connected.'),
        );

        return response()->json(['data' => ['sent' => true]]);
    }

    /**
     * @return array{enabled: bool, configured: bool, instance: string, state: string|null, error: string|null}
     */
    private function status(): array
    {
        $state = null;
        $error = null;

        if ($this->client->isConfigured()) {
            try {
                $state = $this->client->stateValue();
            } catch (ApiException $failure) {
                $error = $failure->getMessage();
            }
        }

        return [
            'enabled' => $this->client->isEnabled(),
            'configured' => $this->client->isConfigured(),
            'instance' => $this->client->instance(),
            'state' => $state,
            'error' => $error,
        ];
    }

    /**
     * @return list<array{group_jid: string, subject: string, is_active: bool}>
     */
    private function groupPayload(): array
    {
        return WhatsAppGroup::query()
            ->orderBy('subject')
            ->get()
            ->map(fn (WhatsAppGroup $group): array => [
                'group_jid' => $group->group_jid,
                'subject' => $group->subject,
                'is_active' => $group->is_active,
            ])
            ->values()
            ->all();
    }
}
