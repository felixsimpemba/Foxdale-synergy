<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\InviteUser;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRolesRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Services\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Filters: q (name or email), role, team_id, is_active, archived (only archived users).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles', 'teams')
            ->when($request->boolean('archived'), fn (Builder $query) => $query->onlyTrashed())
            ->when($request->filled('q'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$request->string('q').'%')
                ->orWhere('email', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('role'), fn (Builder $query) => $query->role($request->string('role')->toString()))
            ->when($request->filled('team_id'), fn (Builder $query) => $query->whereRelation('teams', 'teams.id', $request->integer('team_id')))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, InviteUser $inviteUser): JsonResponse
    {
        Gate::authorize('create', User::class);

        $user = $inviteUser->handle($request->validated());

        return (new UserResource($user->load('roles', 'teams')))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $user->update($request->validated());

        return new UserResource($user->load('roles', 'teams'));
    }

    public function assignRoles(AssignRolesRequest $request, User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('assignRoles', $user);

        $roles = $request->validated('roles');
        $before = $user->getRoleNames()->values()->all();

        if ($this->wouldRemoveLastAdmin($user, $roles)) {
            throw new ApiException('At least one active admin must remain.', 'last_admin');
        }

        $user->syncRoles($roles);
        $audit->record('user.roles_changed', $user, ['before' => $before, 'after' => array_values($roles)]);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Disable login and revoke every token at once.
     */
    public function deactivate(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('changeStatus', $user);

        if ($this->isLastActiveAdmin($user)) {
            throw new ApiException('At least one active admin must remain.', 'last_admin');
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();
        });

        $audit->record('user.deactivated', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    public function activate(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('changeStatus', $user);

        $user->forceFill(['is_active' => true])->save();
        $audit->record('user.activated', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Archive the account: sign the person out, hide them from lists and pickers, and keep
     * every incident, comment and audit entry attached. Restore brings the account back.
     */
    public function destroy(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('delete', $user);

        if ($this->isLastActiveAdmin($user)) {
            throw new ApiException('At least one active admin must remain.', 'last_admin');
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        $audit->record('user.archived', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    public function restore(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('restore', $user);

        if (! $user->trashed()) {
            throw new ApiException('This account is not archived.', 'not_archived');
        }

        $user->restore();
        $audit->record('user.restored', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Email a fresh invitation link to someone who has not signed in yet. Earlier links stop
     * working, since the broker keeps one token per user.
     */
    public function resendInvite(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('update', $user);

        if ($user->last_login_at !== null) {
            throw new ApiException('This person has already signed in. They can use "Forgot password" to get a reset link.', 'already_signed_in');
        }

        if (! $user->is_active) {
            throw new ApiException('Reactivate the account before sending a new invitation.', 'user_inactive');
        }

        $user->notify(new UserInvited(Password::broker('invites')->createToken($user)));
        $audit->record('user.invite_resent', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * For someone who lost their authenticator: remove 2FA and sign them out everywhere. Roles
     * that require 2FA are asked to set it up again at their next sign-in. Admins reset their
     * own 2FA from their account instead, which needs their password.
     */
    public function resetTwoFactor(User $user, TwoFactorService $twoFactor, AuditLogger $audit): UserResource
    {
        Gate::authorize('changeStatus', $user);

        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException('Two-factor authentication is not set up for this person.', 'two_factor_not_enabled');
        }

        DB::transaction(function () use ($user, $twoFactor): void {
            $twoFactor->disable($user);
            $user->tokens()->delete();
        });

        $audit->record('user.two_factor_reset', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Email a reset link so the person chooses a new password. The admin never sees it.
     */
    public function sendPasswordReset(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('sendPasswordReset', $user);

        if (! $user->is_active) {
            throw new ApiException('Reactivate the account before sending a password reset.', 'user_inactive');
        }

        $status = Password::broker('users')->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            throw new ApiException('A reset email was sent recently. Wait a minute before sending another.', 'password_reset_throttled', 429);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            throw new ApiException('The password reset email could not be sent.', 'password_reset_failed');
        }

        $audit->record('user.password_reset_sent', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Sign the person out on every device without changing their password or status.
     */
    public function revokeSessions(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('revokeSessions', $user);

        $revoked = $user->tokens()->delete();
        $audit->record('user.sessions_revoked', $user, ['tokens_revoked' => $revoked]);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * Login lockouts are keyed by email in the rate limiter, with no expiry visible in the app.
     */
    public function clearLockout(User $user, AuditLogger $audit): UserResource
    {
        Gate::authorize('clearLockout', $user);

        RateLimiter::clear('login-lockout:'.Str::lower($user->email));
        $audit->record('user.lockout_cleared', $user);

        return new UserResource($user->load('roles', 'teams'));
    }

    /**
     * @param  list<string>  $newRoles
     */
    private function wouldRemoveLastAdmin(User $user, array $newRoles): bool
    {
        $admin = RoleName::Admin->value;

        if (! $user->hasRole($admin) || in_array($admin, $newRoles, true)) {
            return false;
        }

        return User::role($admin)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
    }

    /**
     * True when deactivating or archiving this person would leave no active admin.
     */
    private function isLastActiveAdmin(User $user): bool
    {
        return $user->is_active
            && $user->hasRole(RoleName::Admin->value)
            && User::role(RoleName::Admin->value)->where('is_active', true)->whereKeyNot($user->id)->doesntExist();
    }
}
