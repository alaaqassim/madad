<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Services\Competition\CsvQuestionReader;
use App\Services\Competition\QuestionImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Requirement 1 — import questions and correct answers.
 *
 * Operational, not routed: there is no HTTP surface for this.
 */
class MadadImportQuestions extends Command
{
    protected $signature = 'madad:import-questions
                            {competition : Competition id}
                            {file : Path to a UTF-8 CSV export of the question workbook}
                            {--delimiter=, : Column delimiter}';

    protected $description = 'Import the question bank for a competition (safe to rerun)';

    public function handle(CsvQuestionReader $reader, QuestionImportService $importer): int
    {
        $competition = Competition::query()->find($this->argument('competition'));

        if ($competition === null) {
            $this->error('Competition not found.');

            return self::FAILURE;
        }

        try {
            $rows = iterator_to_array($reader->read(
                (string) $this->argument('file'),
                (string) $this->option('delimiter'),
            ));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $importer->import($competition, $rows);

        $this->table(['inserted', 'updated', 'rejected'], [[
            $summary->insertedCount(),
            $summary->updatedCount(),
            $summary->rejectedCount(),
        ]]);

        // Rejected rows are reported, never swallowed.
        foreach ($summary->errors as $error) {
            $this->warn(sprintf(
                'line %d (question %s): %s',
                $error['line'],
                $error['question_number'] ?? '?',
                implode(' | ', $error['errors']),
            ));
        }

        return $summary->hasErrors() ? self::INVALID : self::SUCCESS;
    }
}
