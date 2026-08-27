<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contestant's participation: import record, account provisioning state,
 * credential delivery state, exam state and result, in a single row.
 *
 * The ENTIRE exam lives on this row. `question_order` is the contestant's own
 * randomised paper — a JSON array of competition_questions ids. `current_question`
 * is a ZERO-BASED INDEX into that array, never a question id. `answers` is one
 * character per position.
 *
 * TWO timing anchors, and they answer two different questions. `started_at`
 * bounds the whole attempt (started_at + the personal allowance).
 * `current_question_started_at` is when the LIVE question became live — which
 * under immediate advance is the moment the previous answer landed, not a
 * position on a fixed grid. Every deadline the API reports is derived from the
 * pair on the spot; no expiry is ever persisted.
 *
 * `user_id` is nullable on purpose — the participation row is created before
 * the account, so a failed provisioning attempt is a visible, retryable row
 * rather than no record at all.
 *
 * No plaintext credential is ever stored here. The hash lives on users.password.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $account_status
 * @property string $email_status
 * @property string $exam_status
 * @property int $correct_answers
 * @property int $answered_questions
 */
class CompetitionUser extends Model
{
    public const ACCOUNT_PENDING = 'pending';

    public const ACCOUNT_CREATED = 'created';

    public const ACCOUNT_FAILED = 'failed';

    public const EMAIL_PENDING = 'pending';

    public const EMAIL_SENT = 'sent';

    public const EMAIL_FAILED = 'failed';

    public const EXAM_NOT_STARTED = 'not_started';

    public const EXAM_IN_PROGRESS = 'in_progress';

    public const EXAM_COMPLETED = 'completed';

    /** The `answers` placeholder for a position with no recorded option. */
    public const NO_ANSWER = '-';

    /**
     * Millisecond precision, because started_at / completed_at are datetime(3)
     * and that precision is part of the locked schema contract. created_at and
     * updated_at are datetime(0) and the engine narrows them on write, which is
     * harmless for audit columns.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'user_id',
        'contestant_name',
        'contestant_email',
        'source_reference',
        'account_status',
        'credentials_generated_at',
        'email_status',
        'email_attempts',
        'credentials_sent_at',
        'email_last_error',
        'exam_status',
        'started_at',
        'completed_at',
        'question_order',
        'current_question',
        'current_question_started_at',
        'answers',
        'correct_answers',
        'answered_questions',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'email_attempts' => 'integer',
            'correct_answers' => 'integer',
            'answered_questions' => 'integer',
            'current_question' => 'integer',
            // A JSON array of competition_questions.id held as a string. The
            // cast is the only place it is decoded, so nothing else has to know
            // how the paper is stored.
            'question_order' => 'array',
            'credentials_generated_at' => 'datetime',
            'credentials_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'current_question_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /*
     * No competition relation. There is one competition and its configuration
     * is the CompetitionSettings singleton, which nothing here needs a key to.
     */

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAccount(): bool
    {
        return $this->account_status === self::ACCOUNT_CREATED && $this->user_id !== null;
    }

    public function isCompleted(): bool
    {
        return $this->exam_status === self::EXAM_COMPLETED;
    }

    public function isInProgress(): bool
    {
        return $this->exam_status === self::EXAM_IN_PROGRESS;
    }

    /**
     * Never started.
     *
     * Both halves are checked on purpose. `current_question = 0` does NOT mean
     * "not started" — a contestant who has pressed Begin and not yet answered
     * anything sits at index 0 with the clock running, and treating that as a
     * fresh start would restart their timeline.
     */
    public function isNotStarted(): bool
    {
        return $this->exam_status === self::EXAM_NOT_STARTED && $this->started_at === null;
    }

    /** Has pressed Begin, whatever their index. */
    public function hasStarted(): bool
    {
        return ! $this->isNotStarted();
    }

    /**
     * When the LIVE question became live.
     *
     * Falls back to `started_at` only for a row that predates this column — a
     * contestant at index 0 is the case where the two coincide anyway. Nothing
     * writes an in-progress row without it, and preflight reports any that
     * exist rather than letting the fallback hide them.
     */
    public function questionStartedAt(): ?Carbon
    {
        return $this->current_question_started_at ?? $this->started_at;
    }

    /**
     * The contestant's own paper: competition_questions ids in their order.
     *
     * @return list<int>
     */
    public function order(): array
    {
        return array_values(array_map('intval', $this->question_order ?? []));
    }

    /** The real question id at a position, or null if the position is off the paper. */
    public function questionIdAt(int $index): ?int
    {
        return $this->order()[$index] ?? null;
    }

    /** The option recorded at a position, or null where none was given. */
    public function answerAt(int $index): ?string
    {
        $mark = substr((string) $this->answers, $index, 1);

        return ($mark === false || $mark === '' || $mark === self::NO_ANSWER) ? null : $mark;
    }
}
