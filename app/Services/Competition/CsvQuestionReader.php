<?php

namespace App\Services\Competition;

use Generator;
use RuntimeException;

/**
 * Reads the question bank from a delimited file using PHP's own CSV support.
 *
 * No spreadsheet package is installed in this project, so .xlsx cannot be read
 * yet. Exporting the supplied workbook to CSV (UTF-8) is the supported route
 * today; adding an .xlsx reader later means writing one more class with the
 * same output shape and changing nothing else.
 */
class CsvQuestionReader
{
    /** @return Generator<int, array<string, mixed>> */
    public function read(string $path, string $delimiter = ','): Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read question file: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open question file: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, $delimiter);

            if ($header === false) {
                throw new RuntimeException('Question file is empty.');
            }

            // Excel writes a UTF-8 BOM; left in place it corrupts the first header.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(
                fn ($h) => str_replace(' ', '_', strtolower(trim((string) $h))),
                $header
            );

            $missing = array_diff(QuestionImportService::COLUMNS, $header);

            if ($missing !== []) {
                throw new RuntimeException(
                    'Question file is missing required columns: '.implode(', ', $missing)
                );
            }

            while (($record = fgetcsv($handle, 0, $delimiter)) !== false) {
                // A trailing blank line is not a row worth reporting on.
                if ($record === [null] || $record === ['']) {
                    continue;
                }

                $record = array_pad($record, count($header), null);

                yield array_combine($header, array_slice($record, 0, count($header)));
            }
        } finally {
            fclose($handle);
        }
    }
}
