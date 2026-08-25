<?php

namespace App\Policies;

use App\Models\CompetitionUser;
use App\Models\User;

/**
 * Ownership of a participation row.
 *
 * The exam flow does not rely on this policy for its safety — it never accepts
 * a participation id from a request, so there is nothing to authorise. The
 * policy exists as an explicit, testable statement of the rule for any future
 * code path that does receive a CompetitionUser from outside.
 */
class CompetitionUserPolicy
{
    public function view(User $user, CompetitionUser $participation): bool
    {
        return $participation->user_id === $user->id;
    }

    public function answer(User $user, CompetitionUser $participation): bool
    {
        return $participation->user_id === $user->id
            && $participation->exam_status !== CompetitionUser::EXAM_COMPLETED;
    }
}
