<?php

namespace App\Services\Competition;

use App\Models\CompetitionSettings;
use RuntimeException;

/**
 * Writes an extraction to a CSV file the doctor can open in Excel.
 *
 * ─── WHY CSV AND NOT .XLSX ──────────────────────────────────────────────────
 * No spreadsheet package is installed and none is being added for this. A
 * UTF-8 CSV with a byte-order mark is what Excel on Windows needs in order to
 * render Arabic names correctly — without the BOM it guesses the ANSI code page
 * and the names arrive as mojibake. If a genuine .xlsx requirement is later
 * confirmed, that is a new decision with a new dependency behind it.
 *
 * ─── WHAT IS NEVER WRITTEN ──────────────────────────────────────────────────
 * No password, no password hash, no answer key, no per-question detail, no
 * internal secret. Only the columns listed in HEADINGS.
 *
 * Output is deterministic: the same data produces a byte-identical file, so two
 * extractions can be diffed to prove nothing moved.
 */
class ResultExporter
{
    /** Excel on Windows needs this to read the file as UTF-8. */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Clear column headings, in a fixed order.
     *
     * @var array<string, string> csv heading => row key
     */
    public const HEADINGS = [
        'rank' => 'rank',
        'contestant_name' => 'name',
        'contestant_email' => 'email',
        'correct_answers' => 'correct_answers',
        'total_questions' => 'total_questions',
        'answered_questions' => 'answered_questions',
        'started_at' => 'started_at',
        'completed_at' => 'completed_at',
    ];

    public function __construct(private readonly ResultService $results) {}

    /**
     * Extract completed contestants to a CSV file.
     *
     * `rank` is a position within THIS file, computed at write time. It is not
     * stored anywhere and it is not a ruling on ties — contestants on equal
     * scores get consecutive numbers only because a file has rows in an order.
     *
     * @param  int  $limit  0 for every completed contestant
     * @return array<string, mixed> the extraction payload, including the cutoff warning
     */
    public function export(CompetitionSettings $settings, string $path, int $limit = 100): array
    {
        $payload = $this->results->topN($settings, $limit);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException("Export directory does not exist: {$directory}");
        }

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write the export file: {$path}");
        }

        try {
            fwrite($handle, self::BOM);
            fputcsv($handle, array_keys(self::HEADINGS));

            foreach ($payload['rows'] as $index => $row) {
                $line = [];

                foreach (self::HEADINGS as $key) {
                    $line[] = $key === 'rank'
                        ? $index + 1
                        : $this->neutralise($row[$key] ?? '');
                }

                fputcsv($handle, $line);
            }
        } finally {
            fclose($handle);
        }

        return $payload;
    }

    /**
     * Stops Excel from evaluating a contestant's name as a formula.
     *
     * A name beginning =, +, - or @ is a spreadsheet expression to Excel, which
     * is how a CSV becomes an attack on whoever opens it. Prefixing a single
     * quote makes Excel treat the cell as literal text; the character is not
     * displayed, and ordinary Arabic and Latin names are untouched.
     */
    private function neutralise(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', '	', '
'], true)
            ? "'".$value
            : $value;
    }
}
