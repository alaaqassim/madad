<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one competition's configuration — a singleton, enforced by the database.
 *
 * Madad Phase 1 runs a single competition, so there is no competition to
 * select, no competition id on any route, and no competition_id on any table.
 * `CHECK (id = 1)` in the schema means a second row cannot be created even by
 * accident, and the migration seeds the first, so `current()` always resolves.
 *
 * ─── TWO DIFFERENT CLOCKS LIVE HERE, AND THEY ARE NOT THE SAME THING ────────
 *
 *   starts_at / ends_at      the GLOBAL availability window — when the portal
 *                            may be used at all, by anyone.
 *   exam_duration_minutes    the PERSONAL allowance each contestant gets,
 *                            counted from their own Begin.
 *
 * A contestant's effective end is the earlier of the two:
 *
 *     min( competition_users.started_at + duration ,  ends_at )
 *
 * so a contestant beginning at 10:15 against a window that ends at 11:00 gets
 * 45 minutes, not 60. Nothing derived from this is stored: every deadline is
 * recomputed on the request that reports it, which is why none of them can
 * drift from the values in this row.
 *
 * @property int $id
 * @property string $name
 * @property string $status
 * @property bool $show_result
 * @property int $question_count
 * @property int $seconds_per_question
 * @property int $exam_duration_minutes
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class CompetitionSettings extends Model
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

    /** There is exactly one settings row, and this is its id. */
    public const SINGLETON_ID = 1;

    protected $table = 'competition_settings';

    /**
     * The key is a fixed 1, not a sequence. Nothing creates settings rows at
     * runtime — the migration seeds the only one there will ever be — and a
     * fixed key is what lets CHECK (id = 1) make a second row impossible.
     */
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'status',
        'show_result',
        'question_count',
        'seconds_per_question',
        'exam_duration_minutes',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'show_result' => 'boolean',
            'question_count' => 'integer',
            'seconds_per_question' => 'integer',
            'exam_duration_minutes' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The settings row, or null if it has been deleted.
     *
     * Deliberately not memoised: the row is read once per request through a
     * primary-key lookup, and a cached copy would let an operator's `madad:status
     * --set closed` go unseen by a request already in flight.
     */
    public static function current(): ?self
    {
        return static::query()->find(self::SINGLETON_ID);
    }

    /*
     * There are deliberately NO relations on this model. Under one competition
     * every contestant and every question already belongs to it, so a relation
     * would only add a join key that the schema no longer has. "All
     * participants" is CompetitionUser::query(); "the bank" is
     * CompetitionQuestion::query().
     */

    // ──────────────────────────────────────────────────────── the portal ────

    /** Status alone. The window is a separate question — see withinWindow(). */
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

    // ─────────────────────────────────────────────── the global window ────

    /** Has the announced window opened? True when no opening time is set. */
    public function windowHasOpened(?Carbon $now = null): bool
    {
        return $this->starts_at === null
            || ($now ?? now())->greaterThanOrEqualTo($this->starts_at);
    }

    /** Has the announced window passed? False when no closing time is set. */
    public function windowHasEnded(?Carbon $now = null): bool
    {
        return $this->ends_at !== null
            && ($now ?? now())->greaterThanOrEqualTo($this->ends_at);
    }

    public function withinWindow(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->windowHasOpened($now) && ! $this->windowHasEnded($now);
    }

    // ────────────────────────────────────────────── derived allowances ────

    /** The personal allowance, in seconds. Never persisted as an end time. */
    public function examDurationSeconds(): int
    {
        return max(1, (int) $this->exam_duration_minutes) * 60;
    }

    public function secondsPerQuestion(): int
    {
        return max(1, (int) $this->seconds_per_question);
    }

    public function questionCount(): int
    {
        return max(0, (int) $this->question_count);
    }

    /**
     * The moment a contestant beginning NOW would run out of time.
     *
     * The earlier of their personal allowance and the global window. This is
     * the single formula the whole engine is built on, and it is written once,
     * here, so nothing can implement a second version of it.
     */
    public function effectiveEndFor(Carbon $startedAt): Carbon
    {
        $personalEnd = $startedAt->copy()->addSeconds($this->examDurationSeconds());

        if ($this->ends_at === null) {
            return $personalEnd;
        }

        $windowEnd = $this->ends_at->copy();

        return $personalEnd->lessThan($windowEnd) ? $personalEnd : $windowEnd;
    }

    /**
     * How long a contestant beginning now would actually get, in seconds.
     *
     * This is what the ready screen must show before Begin: a late starter has
     * to be told they are getting the remainder of the window, not a full hour.
     * Never negative; null only when there is no window at all is not possible
     * here, because the personal allowance always bounds it.
     */
    public function secondsAvailableFrom(?Carbon $now = null): int
    {
        $now ??= now();

        return max(0, (int) floor($now->diffInSeconds($this->effectiveEndFor($now), false)));
    }
}
