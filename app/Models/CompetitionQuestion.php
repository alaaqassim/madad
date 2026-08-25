<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question from the imported bank.
 *
 * `correct_option` is the answer key. It lives only on this table and is
 * $hidden so that no accidental toArray()/toJson() anywhere in the stack can
 * serialise it to a contestant. Grading reads it explicitly; nothing else
 * should.
 *
 * @property int $id
 * @property int $competition_id
 * @property int $question_number
 * @property string $question_text
 * @property string $correct_option
 */
class CompetitionQuestion extends Model
{
    public const OPTIONS = ['A', 'B', 'C', 'D'];

    protected $fillable = [
        'competition_id',
        'question_number',
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
    ];

    /** The answer key never leaves the server through serialisation. */
    protected $hidden = ['correct_option'];

    protected function casts(): array
    {
        return [
            'competition_id' => 'integer',
            'question_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * The contestant-safe shape. Deliberately explicit rather than an $except
     * list, so a future column cannot leak by being forgotten.
     *
     * @return array<string, mixed>
     */
    public function toContestantPayload(): array
    {
        return [
            'question_id' => $this->id,
            'question_text' => $this->question_text,
            'options' => [
                'A' => $this->option_a,
                'B' => $this->option_b,
                'C' => $this->option_c,
                'D' => $this->option_d,
            ],
        ];
    }
}
