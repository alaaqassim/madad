<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The entire contract for answering a question.
 *
 * Only two fields are accepted, and only one of them matters. The server
 * already knows which position the contestant is on and derives the real
 * question id from their own question_order, so `question_id` is a consistency
 * check the client may send — never a choice it gets to make. Correctness,
 * score, sequence and timing are computed server-side; if a client sends them
 * they are not validated, not read, and not reachable.
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
            'question_id' => ['nullable', 'integer', 'min:1'],
            'selected_option' => ['required', 'string', Rule::in(['A', 'B', 'C', 'D'])],
        ];
    }

    /** What the client believes it is answering, if it said. */
    public function questionId(): ?int
    {
        $questionId = $this->validated('question_id');

        return $questionId === null ? null : (int) $questionId;
    }

    public function selectedOption(): string
    {
        return (string) $this->validated('selected_option');
    }
}
