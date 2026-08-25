<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question on one contestant's paper: its persisted position, its
 * server-issued deadline, and its single terminal answer state.
 *
 * `opened_at` and `expires_at` are written once by the server and never
 * extended. A refresh re-serves the same row with the original deadline, so
 * reloading the page cannot buy time.
 *
 * @property int $id
 * @property int $competition_user_id
 * @property int $competition_question_id
 * @property int $sequence
 * @property string|null $selected_option
 * @property bool $is_correct
 * @property bool $timed_out
 */
class CompetitionUserQuestion extends Model
{
    /** datetime(3) on opened_at / expires_at / answered_at — see CompetitionUser. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'competition_user_id',
        'competition_question_id',
        'sequence',
        'opened_at',
        'expires_at',
        'selected_option',
        'answered_at',
        'is_correct',
        'timed_out',
    ];

    protected function casts(): array
    {
        return [
            'competition_user_id' => 'integer',
            'competition_question_id' => 'integer',
            'sequence' => 'integer',
            'is_correct' => 'boolean',
            'timed_out' => 'boolean',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CompetitionUser, $this> */
    public function competitionUser(): BelongsTo
    {
        return $this->belongsTo(CompetitionUser::class);
    }

    /** @return BelongsTo<CompetitionQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CompetitionQuestion::class, 'competition_question_id');
    }

    /** A question is terminal once it has been answered or has timed out. */
    public function isTerminal(): bool
    {
        return $this->answered_at !== null || $this->timed_out;
    }

    public function hasBeenOpened(): bool
    {
        return $this->opened_at !== null;
    }
}
