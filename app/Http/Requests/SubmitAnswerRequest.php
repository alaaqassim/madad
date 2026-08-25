<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The entire contract for answering a question.
 *
 * Only two fields are accepted. Correctness, score, sequence, timing and
 * answered_at are computed by the server; if a client sends them they are not
 * validated, not read, and not reachable — validated() returns these two keys
 * and nothing else.
 */
class SubmitAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'min:1'],
            'selected_option' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D'])],
        ];
    }

    public function questionId(): int
    {
        return (int) $this->validated('question_id');
    }

    public function selectedOption(): string
    {
        return (string) $this->validated('selected_option');
    }
}
