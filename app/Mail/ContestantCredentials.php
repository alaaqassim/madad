<?php

namespace App\Mail;

use App\Models\CompetitionSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one message a contestant receives before the competition.
 *
 * It carries a password, so it is built to be read once and acted on: what the
 * competition is, when it opens, where to sign in, and the two lines of
 * credentials. Nothing else competes with those.
 *
 * The password is a constructor argument and is never stored on the model, in
 * a queue payload we control, or in a log. Delivery is synchronous for the same
 * reason - queueing it would write the plaintext to the jobs table.
 */
class ContestantCredentials extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $contestantName,
        public readonly string $plaintextPassword,
        public readonly string $loginEmail,
        public readonly ?CompetitionSettings $competition = null,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->competition?->name ?? config('app.name');

        return new Envelope(subject: "بيانات الدخول إلى {$name}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contestant-credentials',
            with: [
                'name' => $this->contestantName,
                'email' => $this->loginEmail,
                'password' => $this->plaintextPassword,
                'competitionName' => $this->competition?->name ?? config('app.name'),
                'startsAt' => $this->competition?->starts_at,
                'endsAt' => $this->competition?->ends_at,
                'questionCount' => $this->competition?->questionCount(),
                'secondsPerQuestion' => $this->competition?->secondsPerQuestion(),
                'durationMinutes' => $this->competition?->exam_duration_minutes,
                'portal' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
