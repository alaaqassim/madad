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
sequence number. There is exactly one competition — its configuration is the
`competition_settings` singleton — and the backend resolves the contestant's
participation **from the authenticated session**. There is no identifier a
client could substitute to reach another contestant's paper.

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
  "starts_at": "2026-09-05T09:00:00+00:00",
  "ends_at": "2026-09-05T11:00:00+00:00",
  "exam_duration_minutes": 60,
  "seconds_available": 2700,
  "server_time": "2026-09-05T10:15:00+00:00",
  "participation": { "exam_status": "not_started", "account_status": "created" }
}
```

* `status` — `draft` | `ready` | `open` | `closed`.
* `open` — whether the exam may be used. It requires **both** `status = open`
  **and** the server clock inside `[starts_at, ends_at)`.
* `reason` — `null` when open; otherwise `competition_not_open` or
  **`competition_closed`**. `closed` is terminal: the competition has ended and
  the client must not offer "try again later". A window that has **passed** is
  reported as `competition_closed` too, because waiting cannot help.
* `starts_at` / `ends_at` — the global availability window. Either may be
  `null`, meaning unbounded on that side; `status` then governs alone.
* `exam_duration_minutes` — the personal allowance each contestant gets from
  their own Begin. **60.**
* `seconds_available` — what a contestant beginning **now** would actually get:
  `min(allowance, ends_at − now)`. Show this before Begin. A contestant starting
  at 10:15 against an 11:00 window gets 2700, not 3600, and has to be told.
* `participation` — present only when authenticated; `null` if the logged-in
  user is not a contestant.
* When no competition row exists: `{"competition": null, "status": null, "open": false, "reason": "no_competition", "server_time": "…"}`.

### `POST /api/exam/start` → **200**

Start and resume are the same call. A second start never reshuffles the paper,
never moves `started_at`, and never reopens a slot whose time has passed.

```json
{
  "exam_status": "in_progress",
  "started_at": "2026-09-05T09:00:04+00:00",
  "question": { … see Question payload … },
  "waiting": null
}
```

### `GET /api/exam/current` → **200**

Identical envelope to `/exam/start`, so the client renders one shape.

**Exactly one of `question` and `waiting` is ever non-null**, and both are null
before the exam begins and once it is over.

| `exam_status` | `question` | `waiting` | Means |
|---|---|---|---|
| `not_started` | `null` | `null` | Has not pressed Begin |
| `in_progress` | payload | `null` | A question is live — answer it |
| `in_progress` | `null` | payload | Answered early; the next fixed slot has not opened |
| `completed` | `null` | `null` | The paper, the allowance or the window ended it |

Branch on `exam_status` **first** and `waiting` **second**. A client that reads
`question: null` as "finished" will drop a waiting contestant onto the results
screen with most of their paper unanswered.

### Waiting payload

```json
{
  "sequence": 2,
  "total_questions": 75,
  "opens_at": "2026-09-05T09:00:44+00:00",
  "server_time": "2026-09-05T09:00:09+00:00",
  "seconds_remaining": 35.0
}
```

No question, no options, no ids. `sequence` is the position **about to** open,
so progress stays honest while the contestant waits. `seconds_remaining` is
never greater than `seconds_per_question`, and the wait never exceeds one slot.
Render it, count it down, and when it reaches zero call `GET /api/exam/current`
— exactly as you already do when the question timer reaches zero.

> **Reading the current question is a state change.** The server reconciles the
> contestant's position against elapsed time on every request. A refresh
> re-serves the same question with the same deadline, and time spent away is
> spent: positions whose slots closed while the contestant was disconnected,
> logged out, or on another device are permanently skipped and cannot be
> reclaimed. Reloading the page never buys time.

### The timeline, in full

`competition_users.started_at` is the **only** timing anchor stored. Nothing
records when a question was opened, when a contestant arrived at a position,
when they disconnected, or when they logged out. Every timestamp below is
derived on the request that reports it:

```
s  = seconds_per_question (40)        n  = total_questions (75)
t0 = started_at                       D  = exam_duration_minutes × 60 (3600)

slot i         [ t0 + i·s ,  t0 + (i+1)·s )
time_index     floor( (now − t0) / s )
effective_end  min( t0 + D , ends_at )
expires_at     min( slot_end , effective_end )
```

The exam is over when **any** of three things is true: the paper runs out
(`current_question` reaches `n`), the personal allowance runs out, or the
availability window closes. With 75 × 40 = 3000 seconds of slots inside a
3600-second allowance, the paper is normally what ends it; the allowance and the
window are ceilings that bind only a long paper or a late start.

Two consequences the client must render correctly:

* **Answering early does not shift anything.** Answer position 0 five seconds
  in and position 1 still owns `t0+40 → t0+80`. You get a `waiting` payload for
  those 35 seconds, not a head start.
* **A late start gets less.** A contestant beginning at 10:15 against an 11:00
  window has their last slot trimmed to 11:00 and is completed at 11:00.

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
| `sequence` | Position on **this contestant's** paper, 1-based. It is `current_question + 1`; the paper order itself is never sent. |
| `opened_at` / `expires_at` | ISO 8601, server clock. **Derived** from `started_at + i·s` on every request — nothing per-question is persisted, so there is no stored deadline that could drift or be extended. |
| `server_time` | Use it to correct for client clock skew — never trust the device clock. |
| `seconds_remaining` | Float, derived from `expires_at - server_time`, and never greater than `seconds_per_question`. Display only. |

**Never present, at any point:** `correct_option`, `is_correct`, any other
contestant's identifier, `competition_user_id`, or any scoring internal.

The countdown the contestant sees is a **display** of a decision the server has
already made. Draw it from `expires_at` and `server_time`; the server enforces
the deadline regardless of what the browser shows.

### `POST /api/exam/answer`

```json
{ "question_id": 4211, "selected_option": "B" }
```

`selected_option` is the only field that decides anything. `question_id` is
**optional** and is used solely as a consistency check: the server already knows
which position the contestant is on and resolves the real question from their
own paper, so a client can never choose which question it answers. Sending a
different id is refused — `question_expired` if that position's window has
already closed, `question_not_available` otherwise.

`is_correct`, `answered_at`, `sequence`, `expires_at` and anything else in the
body are ignored — the server computes them.

**200**

```json
{
  "accepted": true,
  "sequence": 1,
  "exam_status": "in_progress",
  "next_question": { … Question payload … },
  "waiting": null
}
```

`next_question` and `waiting` follow exactly the table under `/exam/current`:
the tail of an answer is the same state the client would have got by asking, so
a submission needs no follow-up round trip. Answering **early** — which is the
common case — returns `next_question: null` with a `waiting` payload.

`next_question` and `waiting` are both `null` when that was the last question;
`exam_status` is then `completed`.

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
| `question_not_available` | 422 | Not on this paper, already answered, already timed out, not the current question, **or the next fixed slot has not opened yet** | Re-fetch `/exam/current` |
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
   Changing the device clock changes nothing.
2. **There is no grace period.** Exactly `seconds_per_question` (40). An answer
   one millisecond late is `question_expired` and the question is lost.
3. **A timeout is not a failure of the exam.** After `question_expired`, call
   `GET /api/exam/current`; the next slot arrives with its own window.
4. **`closed` is terminal**, and so is a window that has passed. Both stop new
   starts *and* in-progress contestants from resuming, fetching another
   question, or answering. Neither is a pause.
5. **Refreshing is safe and buys nothing.** The same question comes back with
   the same deadline, because the deadline is arithmetic rather than a record.
6. **Never cache a question payload across a reload** — always re-fetch
   `/exam/current`, which is the authority on what is live.
7. **`in_progress` with no question is not the end.** Check `waiting` before
   showing a completion screen.
8. **Time never pauses.** A disconnect, a logout, a closed browser and a second
   device all change nothing: elapsed slots are spent and are not given back.
