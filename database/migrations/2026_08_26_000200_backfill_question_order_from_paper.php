<?php

/**
 * Madad Phase 1 — convert existing papers to the Array + Index model.
 *
 * Runs between the column addition and the removal of
 * competition_user_questions, so no participation is lost in the change:
 *
 *   question_order              ids ordered by the paper's own sequence
 *   answers                     selected_option per position, '-' where none
 *   current_question            how many positions are already terminal
 *   current_question_started_at when the contestant arrived at that position
 *
 * Participations with no paper (never started, or never provisioned) are left
 * NULL — they will be given an order the first time they start.
 *
 * Aggregates are deliberately NOT recomputed here. They were already derived
 * from the rows being converted, and rewriting them would hide any pre-existing
 * disagreement that the preflight check is there to surface.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasTable('competition_user_questions')) {
            return;
        }

        $secondsByCompetition = DB::table('competitions')
            ->pluck('seconds_per_question', 'id')
            ->all();

        DB::table('competition_users')
            ->orderBy('id')
            ->select('id', 'competition_id', 'started_at')
            ->chunk(self::CHUNK, function ($participations) use ($secondsByCompetition): void {
                $ids = $participations->pluck('id')->all();

                $rows = DB::table('competition_user_questions')
                    ->whereIn('competition_user_id', $ids)
                    ->orderBy('competition_user_id')
                    ->orderBy('sequence')
                    ->get(['competition_user_id', 'competition_question_id', 'sequence', 'opened_at', 'selected_option', 'answered_at', 'timed_out'])
                    ->groupBy('competition_user_id');

                foreach ($participations as $participation) {
                    $paper = $rows->get($participation->id);

                    if ($paper === null || $paper->isEmpty()) {
                        continue;
                    }

                    $order = [];
                    $answers = '';
                    $consumed = 0;
                    $firstLiveOpenedAt = null;

                    foreach ($paper as $row) {
                        $order[] = (int) $row->competition_question_id;
                        $answers .= $row->selected_option ?? '-';

                        $terminal = $row->answered_at !== null || (int) $row->timed_out === 1;

                        if ($terminal) {
                            $consumed++;

                            continue;
                        }

                        if ($firstLiveOpenedAt === null && $row->opened_at !== null) {
                            $firstLiveOpenedAt = $row->opened_at;
                        }
                    }

                    $seconds = (int) ($secondsByCompetition[$participation->competition_id] ?? 40);

                    DB::table('competition_users')
                        ->where('id', $participation->id)
                        ->update([
                            'question_order' => json_encode($order),
                            'answers' => $answers,
                            'current_question' => $consumed,
                            'current_question_started_at' => $this->arrivedAt(
                                $firstLiveOpenedAt,
                                $participation->started_at,
                                $consumed,
                                $seconds,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('competition_users')->update([
            'question_order' => null,
            'answers' => null,
            'current_question' => null,
            'current_question_started_at' => null,
        ]);
    }

    /**
     * When the contestant reached their current position.
     *
     * The old model recorded it as the live question's opened_at, so that is
     * used when it exists. Otherwise the position is placed on the fixed
     * timeline, which is where a contestant who never opened the question would
     * have been anyway.
     */
    private function arrivedAt(?string $openedAt, ?string $startedAt, int $index, int $seconds): ?string
    {
        if ($openedAt !== null) {
            return $openedAt;
        }

        if ($startedAt === null) {
            return null;
        }

        return (new DateTimeImmutable($startedAt))
            ->modify('+'.($index * $seconds).' seconds')
            ->format('Y-m-d H:i:s.v');
    }
};
