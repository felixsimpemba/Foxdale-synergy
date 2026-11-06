<?php

namespace App\Http\Requests\Pulse;

/**
 * Anyone with the survey link may create a response; the route is rate limited and the
 * honeypot in the parent request catches naive bots.
 */
class StorePulseResponseRequest extends PulseResponseRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
