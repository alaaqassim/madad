<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One contestant's participation: import record, account provisioning state,
 * credential delivery state, exam state and result, in a single row.
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

    /**
     * Millisecond precision, because started_at / completed_at are datetime(3)
     * and that precision is part of the locked schema contract. created_at and
     * updated_at are datetime(0) and the engine narrows them on write, which is
     * harmless for audit columns.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'competition_id',
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
        'correct_answers',
        'answered_questions',
    ];

    protected function casts(): array
    {
        return [
            'competition_id' => 'integer',
            'user_id' => 'integer',
            'email_attempts' => 'integer',
            'correct_answers' => 'integer',
            'answered_questions' => 'integer',
            'credentials_generated_at' => 'datetime',
            'credentials_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CompetitionUserQuestion, $this> */
    public function paper(): HasMany
    {
        return $this->hasMany(CompetitionUserQuestion::class)->orderBy('sequence');
    }

    public function hasAccount(): bool
    {
        return $this->account_status === self::ACCOUNT_CREATED && $this->user_id !== null;
    }

    public function isCompleted(): bool
    {
        return $this->exam_status === self::EXAM_COMPLETED;
    }
}
