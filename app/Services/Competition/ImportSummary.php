<?php

namespace App\Services\Competition;

/** What an import run did, including every row it refused and why. */
class ImportSummary
{
    /** @var list<int> */
    public array $insertedNumbers = [];

    /** @var list<int> */
    public array $updatedNumbers = [];

    /** @var list<array{line: int, question_number: int|null, errors: list<string>}> */
    public array $errors = [];

    public function inserted(int $questionNumber): void
    {
        $this->insertedNumbers[] = $questionNumber;
    }

    public function updated(int $questionNumber): void
    {
        $this->updatedNumbers[] = $questionNumber;
    }

    /** @param list<string> $errors */
    public function reject(int $line, int|string|null $questionNumber, array $errors): void
    {
        $this->errors[] = [
            'line' => $line,
            'question_number' => is_numeric($questionNumber) ? (int) $questionNumber : null,
            'errors' => $errors,
        ];
    }

    public function insertedCount(): int
    {
        return count($this->insertedNumbers);
    }

    public function updatedCount(): int
    {
        return count($this->updatedNumbers);
    }

    public function rejectedCount(): int
    {
        return count($this->errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'inserted' => $this->insertedCount(),
            'updated' => $this->updatedCount(),
            'rejected' => $this->rejectedCount(),
            'errors' => $this->errors,
        ];
    }
}
