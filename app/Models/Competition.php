<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A competition and its operational configuration.
 *
 * `status` is the SOLE authority over whether the portal is open. `starts_at`
 * and `ends_at` are display metadata and must never gate access — two
 * authorities would mean two readings of the same question.
 *
 * @property int $id
 * @property string $name
 * @property string $status
 * @property bool $show_result
 * @property int $question_count
 * @property int $seconds_per_question
 */
class Competition extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_READY,
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'name',
        'status',
        'show_result',
        'question_count',
        'seconds_per_question',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'show_result' => 'boolean',
            'question_count' => 'integer',
            'seconds_per_question' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return HasMany<CompetitionQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(CompetitionQuestion::class);
    }

    /** @return HasMany<CompetitionUser, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CompetitionUser::class);
    }

    /** The single question the backend asks before letting anyone near the exam. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Terminal. `closed` means the competition has ENDED — not merely that new
     * starts are blocked — so an in-progress contestant may not resume, fetch
     * another question, or submit another answer.
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
