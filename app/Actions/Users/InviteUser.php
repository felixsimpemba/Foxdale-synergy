<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates an account with an unusable random password and emails an invitation link
 * so the user sets their own password.
 */
class InviteUser
{
    /**
     * @param  array{name: string, email: string, phone?: string|null, mattermost_username?: string|null, timezone?: string|null, roles: list<string>, team_ids?: list<int>|null}  $data
     */
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'mattermost_username' => $data['mattermost_username'] ?? null,
                'timezone' => $data['timezone'] ?? config('incidents.display_timezone'),
                'password' => Str::random(64),
            ]);

            $user->syncRoles($data['roles']);
            $user->teams()->sync($data['team_ids'] ?? []);

            return $user;
        });

        $user->notify(new UserInvited(Password::broker('invites')->createToken($user)));

        return $user;
    }
}
