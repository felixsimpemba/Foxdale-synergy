<?php

namespace App\Http\Requests\Pulse;

use App\Models\PulseResponse;

/**
 * Editing needs the private token handed out when the response was created. A wrong token
 * looks exactly like a response that does not exist, so ids cannot be probed.
 */
class UpdatePulseResponseRequest extends PulseResponseRequest
{
    public function authorize(): bool
    {
        $response = $this->route('pulseResponse');
        $token = (string) $this->header('X-Pulse-Token', '');

        abort_unless($response instanceof PulseResponse && $response->hasEditToken($token), 404);

        return true;
    }
}
