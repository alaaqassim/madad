<?php

namespace App\Services\Competition;

use App\Models\CompetitionUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates the contestant's account and issues a credential.
 *
 * The plaintext password exists only as a return value, travels to the gateway
 * in memory, and is never written to any table. Only the bcrypt hash is
 * persisted, on users.password.
 *
 * Rerunning is safe. An already-linked participation reuses its user, and an
 * email that already exists in `users` is adopted rather than duplicated, so a
 * second run issues a fresh password to the same account instead of creating a
 * second one.
 */
class ContestantProvisioningService
{
    /** Long enough to resist guessing, short enough to retype from an email. */
    private const PASSWORD_LENGTH = 12;

    /**
     * Creates or links the account and sets a freshly generated password.
     *
     * @return string the plaintext credential — hand it to the gateway and let
     *                it fall out of scope; it is not recoverable afterwards
     */
    public function provision(CompetitionUser $participation): string
    {
        $plaintext = $this->generatePassword();

        DB::transaction(function () use ($participation, $plaintext) {
            $user = $this->resolveUser($participation);

            // 'hashed' cast on the model does the bcrypt; no plaintext is stored.
            $user->password = $plaintext;
            $user->save();

            $participation->forceFill([
                'user_id' => $user->id,
                'account_status' => CompetitionUser::ACCOUNT_CREATED,
                'credentials_generated_at' => now(),
            ])->save();
        });

        return $plaintext;
    }

    /** Marks provisioning as failed without inventing an account. */
    public function markFailed(CompetitionUser $participation): void
    {
        $participation->forceFill([
            'account_status' => CompetitionUser::ACCOUNT_FAILED,
        ])->save();
    }

    /**
     * Never creates a second account for the same person.
     *
     * users.email is unique, so adopting an existing row is both the correct
     * semantics and the only thing the database would allow.
     */
    private function resolveUser(CompetitionUser $participation): User
    {
        if ($participation->user_id !== null) {
            $existing = User::query()->find($participation->user_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $byEmail = User::query()->where('email', $participation->contestant_email)->first();

        if ($byEmail !== null) {
            return $byEmail;
        }

        try {
            return User::query()->create([
                'name' => $participation->contestant_name,
                'email' => $participation->contestant_email,
                'password' => Str::password(self::PASSWORD_LENGTH),
            ]);
        } catch (Throwable $e) {
            // Lost a race against a concurrent provisioning run; the unique
            // index did its job, so adopt the row the winner created.
            $raced = User::query()->where('email', $participation->contestant_email)->first();

            if ($raced !== null) {
                return $raced;
            }

            throw $e;
        }
    }

    private function generatePassword(): string
    {
        return Str::password(self::PASSWORD_LENGTH, letters: true, numbers: true, symbols: false);
    }
}
