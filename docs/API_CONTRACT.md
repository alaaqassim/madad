# Madad Phase 1 — Contestant API Contract

**Status: FROZEN for Phase 1.** This is the contract the Vue front end is written
against. Changing a route, a payload key, an error `reason` or an HTTP status is a
breaking change and must be a deliberate, re-agreed decision.

Locked by `tests/Feature/ContestantFlowHttpTest.php`, `ErrorContractTest.php`,
`CompetitionClosureTest.php` and `SessionSecurityTest.php`.

---

## 1. Transport and authentication

| Property | Value |
|---|---|
| Base path | `/api` |
| Format | JSON in, JSON out. Always send `Accept: application/json`. |
| Authentication | Laravel **session cookie** (the `web` guard). No tokens, no Sanctum. |
| CSRF | Required on every `POST`. |
| Origin | The Vue app is served from the same origin as the API. |

### How the Vue client authenticates

1. `GET /api/competition/status` — a public call. Laravel sets the `XSRF-TOKEN`
   cookie on the response.
2. Send that cookie's value back in the `X-XSRF-TOKEN` header on every `POST`.
   Axios does this automatically for same-origin requests; `fetch` does not, so
   set it explicitly.
3. `POST /api/login` — on success the session cookie identifies the contestant
   for every subsequent call.

No request in this API ever accepts a competition id, a participation id or a
sequence number. The backend resolves the competition, and resolves the
contestant's participation **from the authenticated session**. There is no
identifier a client could substitute to reach another contestant's paper.

---

## 2. Routes

| Method | Path | Auth | Throttle | Purpose |
|---|---|---|---|---|
| `POST` | `/api/login` | guest | 6/min per IP + 5 per email+IP | Start a session |
| `POST` | `/api/logout` | required | — | End the session |
| `GET`  | `/api/competition/status` | public | — | Is the portal usable? |
| `POST` | `/api/exam/start` | required | — | Start **or resume** the exam |
| `GET`  | `/api/exam/current` | required | — | The question awaiting an answer |
| `POST` | `/api/exam/answer` | required | 120/min | Submit one answer |
| `GET`  | `/api/exam/result` | required | — | The contestant's own result |

There is deliberately **no** endpoint for importing questions, provisioning
accounts, opening or closing the portal, or extracting results. Those are
artisan commands, so there is no public surface to defend. See
`docs/OPERATIONS.md`.

---

## 3. Payloads

### `POST /api/login`

```json
{ "email": "contestant@example.com", "password": "..." }
```

**200**

```json
{ "user": { "id": 12, "name": "أحمد", "email": "contestant@example.com" } }
```

### `POST /api/logout` → **200**

```json
{ "message": "Logged out." }
```

### `GET /api/competition/status` → **200**

Public. Answers even when the portal is shut, so the client can explain why.

```json
{
  "competition": "Madad Phase 1",
  "status": "open",
  "open": true,
  "reason": null,
  "total_questions": 75,
  "seconds_per_question": 40,
  "show_result": false,
  "server_time": "2026-09-05T09:00:00+00:00",
  "participation": { "exam_status": "not_started", "account_status": "created" }
}
```

* `status` — `draft` | `ready` | `open` | `closed`.
* `open` — the only thing that decides whether the exam may be used.
* `reason` — `null` when open; otherwise `competition_not_open` or
  **`competition_closed`**. `closed` is terminal: the competition has ended and
  the client must not offer "try again later".
* `participation` — present only when authenticated; `null` if the logged-in
  user is not a contestant.
* When no competition row exists: `{"competition": null, "status": null, "open": false, "reason": "no_competition", "server_time": "…"}`.

### `POST /api/exam/start` → **200**

Start and resume are the same call. A second start never reshuffles the paper,
never restarts the clock, and never moves a deadline already issued.

```json
{
  "exam_status": "in_progress",
  "started_at": "2026-09-05T09:00:04+00:00",
  "question": { … see Question payload … }
}
```

### `GET /api/exam/current` → **200**

Identical envelope to `/exam/start`, so the client renders one shape.

```json
{
  "exam_status": "in_progress",
  "started_at": "2026-09-05T09:00:04+00:00",
  "question": { … } 
}
```

`question` is `null` once the paper is finished; `exam_status` is then
`completed`.

> **Serving a question is a state change.** The first time a question is
> returned, its `opened_at` and `expires_at` are written from the server clock
> and never change again. A refresh re-serves the same question with the same
> deadline — reloading the page cannot buy time.

### Question payload

Exactly these keys, in this order. Nothing else is ever included.

```json
{
  "question_id": 4211,
  "question_text": "نص السؤال",
  "options": { "A": "…", "B": "…", "C": "…", "D": "…" },
  "sequence": 1,
  "total_questions": 75,
  "opened_at": "2026-09-05T09:00:04+00:00",
  "expires_at": "2026-09-05T09:00:44+00:00",
  "server_time": "2026-09-05T09:00:04+00:00",
  "seconds_remaining": 40.0
}
```

| Key | Notes |
|---|---|
| `question_id` | Send this back with the answer. |
| `sequence` | Position on **this contestant's** paper, 1-based. |
| `opened_at` / `expires_at` | ISO 8601, server clock, millisecond precision, written once. |
| `server_time` | Use it to correct for client clock skew — never trust the device clock. |
| `seconds_remaining` | Float, derived from `expires_at - server_time`. Display only. |

**Never present, at any point:** `correct_option`, `is_correct`, any other
contestant's identifier, `competition_user_id`, or any scoring internal.

The countdown the contestant sees is a **display** of a decision the server has
already made. Draw it from `expires_at` and `server_time`; the server enforces
the deadline regardless of what the browser shows.

### `POST /api/exam/answer`

```json
{ "question_id": 4211, "selected_option": "B" }
```

Only these two fields are read. `is_correct`, `answered_at`, `sequence`,
`expires_at` and anything else in the body are ignored — the server computes
them.

**200**

```json
{
  "accepted": true,
  "sequence": 1,
  "exam_status": "in_progress",
  "next_question": { … Question payload … }
}
```

`next_question` is `null` when that was the last question; `exam_status` is then
`completed`.

**The response never says whether the answer was right.** Returning correctness
per answer would turn the exam into an oracle for the answer key.

### `GET /api/exam/result` → **200**

```json
{
  "exam_status": "completed",
  "completed_at": "2026-09-05T09:29:38+00:00",
  "show_result": true,
  "correct_answers": 63,
  "answered_questions": 71,
  "total_questions": 75
}
```

`correct_answers`, `answered_questions` and `total_questions` are present **only**
when `show_result` is `true` **and** the exam is completed. Otherwise they are
absent from the body entirely — not merely hidden, not sent at all, so a client
that forgets to hide them cannot leak what it never received.

---

## 4. Error contract

Every JSON error carries a stable `reason`. Branch on `reason`, never on
`message` — messages are human-facing and may be translated into Arabic without
notice.

```json
{ "message": "The competition has ended.", "reason": "competition_closed" }
```

Validation errors additionally keep Laravel's per-field `errors` object:

```json
{ "message": "…", "reason": "validation_error", "errors": { "email": ["…"] } }
```

| `reason` | HTTP | When | What the client should do |
|---|---|---|---|
| `validation_error` | 422 | A field is missing or malformed | Show the field errors |
| `invalid_credentials` | 422 | Wrong password **or** unknown address | One generic message — never say which |
| `too_many_attempts` | 429 (route) / 422 (per-email lockout) | Too many login attempts, or >120 answers/min | Ask the contestant to wait |
| `unauthenticated` | 401 | No valid session | Send them to the login screen |
| `not_found` | 404 | Unknown API route | Should not happen; log it |
| `competition_not_open` | 403 | `draft` or `ready`, or no competition exists | "Not open yet" — a retry may succeed later |
| `competition_closed` | 403 | `closed` | **"The competition has ended"** — terminal, offer no retry |
| `not_a_contestant` | 403 | Logged in, but no participation in this competition | "You are not registered" |
| `account_not_provisioned` | 403 | Participation exists, account not created | "Your participation is not yet active" |
| `paper_not_ready` | 409 | Question bank smaller than `question_count` | Operator problem — tell them to contact support |
| `exam_completed` | 409 | The exam has already finished | Navigate to the result screen |
| `no_current_question` | 409 | No question awaiting an answer | Re-fetch `/exam/current` |
| `question_not_available` | 422 | Not on this paper, already answered, already timed out, or not the current question | Re-fetch `/exam/current` |
| `question_expired` | 422 | The answer arrived after `expires_at` | Show "time is up", then re-fetch `/exam/current` |
| `server_error` | 500 | Unexpected failure | Generic apology; retry |

`question_not_available` is deliberately **identical** whether the question
belongs to somebody else, does not exist, or is simply not current. A
distinguishable message would make this endpoint an oracle for probing other
contestants' papers.

No error body ever contains SQL, a stack trace, a model class name, a database
id the client did not already have, or an answer-key clue. This holds even when
`APP_DEBUG` is on: database failures are caught and replaced unconditionally.

---

## 5. The rules the client must respect

1. **The server's clock is the only clock.** Use `server_time` and `expires_at`;
   never the device clock, and never a client-side countdown as the authority.
2. **There is no grace period.** Exactly `seconds_per_question` (40). An answer
   one millisecond late is `question_expired` and the question is lost.
3. **A timeout is not a failure of the exam.** After `question_expired`, call
   `GET /api/exam/current`; the next question arrives with its own full window.
4. **`closed` is terminal.** It stops new starts *and* in-progress contestants
   from resuming, fetching another question, or answering. It is not a pause.
5. **Refreshing is safe and buys nothing.** The same question comes back with
   the same deadline.
6. **Never cache a question payload across a reload** — always re-fetch
   `/exam/current`, which is the authority on what is live.
