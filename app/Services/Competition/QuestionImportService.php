<?php

namespace App\Services\Competition;

use App\Models\Competition;
use App\Models\CompetitionQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports the question bank from the supplied spreadsheet structure.
 *
 * Input is an iterable of associative rows, so the reader (CSV today, .xlsx
 * once a spreadsheet package is approved) is a separate concern from the
 * business rules enforced here.
 *
 * Rerunning is safe: (competition_id, question_number) is unique, so a row for
 * an existing number updates it rather than inserting a duplicate. Nothing is
 * skipped silently — every rejected row comes back with the reason.
 */
class QuestionImportService
{
    public const COLUMNS = [
        'question_number',
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
    ];

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function import(Competition $competition, iterable $rows): ImportSummary
    {
        $summary = new ImportSummary;
        $seenNumbers = [];
        $valid = [];

        foreach ($rows as $offset => $row) {
            // Row 1 is the header in the source file, so data starts at line 2.
            $line = $offset + 2;
            $row = $this->normalise($row);

            $validator = Validator::make($row, [
                'question_number' => ['required', 'integer', 'min:1', 'max:65535'],
                'question_text' => ['required', 'string', 'max:65535'],
                'option_a' => ['required', 'string', 'max:500'],
                'option_b' => ['required', 'string', 'max:500'],
                'option_c' => ['required', 'string', 'max:500'],
                'option_d' => ['required', 'string', 'max:500'],
                'correct_option' => ['required', 'string', 'in:A,B,C,D'],
            ]);

            if ($validator->fails()) {
                $summary->reject($line, $row['question_number'] ?? null, $validator->errors()->all());

                continue;
            }

            $number = (int) $row['question_number'];

            // A duplicate inside one file is an authoring mistake, not an
            // update: silently letting the last row win would hide it.
            if (isset($seenNumbers[$number])) {
                $summary->reject($line, $number, [
                    "Question number {$number} already appears on line {$seenNumbers[$number]} of this import.",
                ]);

                continue;
            }

            $seenNumbers[$number] = $line;
            $valid[] = $row;
        }

        if ($valid !== []) {
            DB::transaction(function () use ($competition, $valid, $summary) {
                $existing = CompetitionQuestion::query()
                    ->where('competition_id', $competition->id)
                    ->pluck('id', 'question_number')
                    ->all();

                foreach ($valid as $row) {
                    $number = (int) $row['question_number'];

                    $attributes = [
                        'question_text' => $row['question_text'],
                        'option_a' => $row['option_a'],
                        'option_b' => $row['option_b'],
                        'option_c' => $row['option_c'],
                        'option_d' => $row['option_d'],
                        'correct_option' => $row['correct_option'],
                    ];

                    if (isset($existing[$number])) {
                        CompetitionQuestion::query()
                            ->whereKey($existing[$number])
                            ->update($attributes);
                        $summary->updated($number);

                        continue;
                    }

                    CompetitionQuestion::query()->create($attributes + [
                        'competition_id' => $competition->id,
                        'question_number' => $number,
                    ]);
                    $summary->inserted($number);
                }
            });
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalise(array $row): array
    {
        $out = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $out[$column] = $value === '' ? null : $value;
        }

        if (is_string($out['correct_option'])) {
            $out['correct_option'] = strtoupper($out['correct_option']);
        }

        return $out;
    }
}
