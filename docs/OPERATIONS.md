# Madad Phase 1 — Operations Runbook

Everything an operator does on competition day. There is **no admin UI**, and no
HTTP route changes competition state — so nothing here is reachable from the
public internet.

---

## 0. The safety gate

Before any command that writes, confirm which database you are on:

```bash
php artisan tinker --execute="echo config('database.connections.mysql.database').' | '.DB::connection()->getDatabaseName().' | '.DB::selectOne('SELECT DATABASE() AS d')->d;"
```

All three must read the same name, and it must be the Madad database.
`php artisan madad:preflight` checks this too and **FAILS** if the three
disagree, or if the connection has landed on a database belonging to another
project (`cms_moher`, `cms_moher_exam_engine`).

Automated tests run on **`madad_test`** (`phpunit.xml`) and never touch
`madad_dev` or any live database.

---

## 1. `madad:preflight` — the competition-day check

```bash
php artisan madad:preflight                # full report
php artisan madad:preflight 2              # a specific competition
php artisan madad:preflight --strict       # exit 1 on warnings too
php artisan madad:preflight --json=out.json
```

**Strictly read-only.** Every statement behind it is a `SELECT`. It is safe to
run while contestants are mid-exam — a plain read does not queue behind the
exam's row locks.

Exit codes: `0` = PASS or WARNING · `1` = FAIL, or WARNING with `--strict`.

It checks:

| Area | Checks |
|---|---|
| Environment | database identity (all three agree), database isolation, `APP_ENV`/`APP_DEBUG`, pending migrations |
| Competition | exists, exactly one, status, `question_count`, `seconds_per_question`, `show_result` |
| Questions | bank ≥ `question_count`, no missing A/B/C/D, non-empty text, `correct_option` in A–D, unique question numbers |
| Contestants | totals, account-status and email-status distributions, usable accounts, duplicate emails, orphan account links, created-without-user |
| Exam data | duplicate sequences, duplicate assignments, answered-and-timed-out, orphan answer timestamps, unopened answers, scoring integrity, **40-second timer windows**, paper length, terminal states, not-started integrity, completed aggregates, completion timestamps |

**Blockers vs warnings.** FAIL is reserved for states in which the competition
cannot correctly run. Undelivered credentials, unprovisioned participations and
a second competition row are **warnings** — no stated business rule makes any of
them a launch blocker, and this command does not invent one.

---

## 2. `madad:status` — inspect, open, close

```bash
php artisan madad:status                          # report only — changes nothing
php artisan madad:status --set=open               # asks for confirmation
php artisan madad:status --set=closed             # asks for confirmation
php artisan madad:status --set=open --force       # non-interactive
php artisan madad:status 2 --set=ready
```

With no `--set` it only reports: competition name, status, portal open,
`question_count`, `seconds_per_question`, `show_result`, contestant totals and
the `not_started` / `in_progress` / `completed` split, plus the question-bank
size.

There is no toggle. A change requires naming both the action and the target
value, so no shape of this command alters anything by accident.

### Opening

`--set=open` runs the **full preflight first** and refuses on any blocker:

```
BLOCKER  [Questions] bank size: only 40 questions for a paper of 75 - papers cannot be built
REFUSED: 1 readiness blocker(s). The competition was NOT opened.
```

Warnings are printed and do not block.

### Closing — this ENDS the competition

`closed` is terminal. Under the confirmed business rule it blocks new starts
**and** stops in-progress contestants from resuming, fetching another question,
or submitting another answer. It is not a pause, and there is no way to reopen
into the same session of play other than setting the status back deliberately.

The command states this, names how many contestants are mid-exam and will be
cut off, and then asks. A non-interactive run refuses unless `--force` is given
— it never silently closes.

---

## 3. `madad:provision` — accounts and credentials

```bash
php artisan madad:provision                    # deliver to pending rows
php artisan madad:provision --dry-run          # report only, change nothing
php artisan madad:provision --retry-failed     # include previously failed rows
php artisan madad:provision --limit=50         # a controlled batch
```

**Rerunning is safe.** Rows whose credential was already delivered are not
selected at all, so a rerun cannot invalidate a password a contestant is already
holding. Selected rows reuse their existing user, or adopt one by email — never
a second account.

The report shows: source participations, selected this run, skipped, accounts
already created, accounts newly created, email delivered, retries attempted,
failures, and the full before/after account- and email-status distribution.
Errors are listed with the gateway's message (capped at 20; the rest stay in
`competition_users.email_last_error`).

**No plaintext credential is ever written** — not to a table, not to a log, not
to this command's output. A retry generates a **new** password and replaces the
stored hash, because the old plaintext no longer exists to be resent. That is
safe: if the first delivery genuinely failed, the contestant never learned the
first password.

---

## 4. `madad:results` — the result file

```bash
php artisan madad:results --top=100 --export=results-top100.csv
php artisan madad:results --top=0   --export=results-all.csv     # every completed contestant
php artisan madad:results --top=100                              # console table
php artisan madad:results --json=results.json
```

Only **completed** contestants are exported.

### File format

UTF-8 **with a byte-order mark**, so Excel on Windows renders Arabic names
correctly rather than as mojibake. Deterministic: the same data produces a
byte-identical file.

| Column | Source |
|---|---|
| `rank` | Position in this file. Computed at write time; never stored. |
| `contestant_name` | `competition_users.contestant_name` |
| `contestant_email` | `competition_users.contestant_email` |
| `correct_answers` | Stored aggregate, recomputed from the answer rows at finalisation |
| `total_questions` | `competitions.question_count` |
| `answered_questions` | Stored aggregate |
| `started_at` | ISO 8601 |
| `completed_at` | ISO 8601 |

No password, no hash, no answer key, no per-question detail, no internal secret.
A name beginning `=`, `+`, `-` or `@` is prefixed with a single quote so Excel
treats it as text instead of evaluating it as a formula.

> **Not `.xlsx`.** No spreadsheet package is installed and none was added. CSV
> with a BOM is what Excel needs for Arabic. A real `.xlsx` requirement would be
> a new decision with a new dependency behind it.

### Top 100 and the tie

Ordering is `correct_answers DESC` **and nothing else**. `id ASC` is appended
purely so repeated extractions return rows in the same order — a stability
device, **not** a ranking rule, and it must not be presented to the business as
one. `tie_break_rule` is `null` in every payload.

If more contestants share the cutoff score than there are places remaining, the
command says so:

```
WARNING: Top-100 cutoff is tied and requires a business decision.
CUTOFF CONTESTED: 5 contestants share the cutoff score of 37, which is more than
the places remaining. No tie-break rule exists, so the boundary of this list is
NOT decided. A business ruling is required.
```

Nobody on an equal score is silently discarded. Rank is never stored: it is a
property of a query, not of a contestant.

---

## 5. `madad:import-questions`

```bash
php artisan madad:import-questions 2 questions.csv
```

Retained and tested, but **not the intended route for the real competition**.
The real question workbook is injected into `competition_questions` directly and
operationally. The runtime exam backend depends only on valid rows existing in
that table; it neither knows nor cares how they got there.

---

## 6. The Email Gateway seam

### What is ready

| Piece | State |
|---|---|
| `App\Services\Competition\CredentialGateway` (interface) | Done. `send(string $email, string $name, string $plaintextPassword): GatewayResult` |
| `GatewayResult` | Done. `delivered()` / `failed(string $error)`. Never carries the credential. |
| `LogCredentialGateway` | Done. The development implementation. Records that a dispatch happened, deliberately **not** what it contained. |
| `CredentialDeliveryService` | Done. Provisions, dispatches, records `email_status`, `email_attempts`, `credentials_sent_at`, `email_last_error`. |
| Failure and retry handling | Done and tested, including re-issue-not-replay. |

### What remains external

**No vendor gateway credentials or configuration have been supplied, so no
production email delivery exists and none is claimed.** `MAIL_MAILER=log` is the
current setting.

### The exact seam

One binding, in `app/Providers/AppServiceProvider.php`:

```php
$this->app->bind(CredentialGateway::class, LogCredentialGateway::class);
```

Attaching a real gateway is:

1. Add `app/Services/Competition/<Vendor>CredentialGateway.php` implementing
   `CredentialGateway`. Return `GatewayResult::delivered()` or
   `GatewayResult::failed($vendorMessage)`. **Do not log the password.**
2. Add its configuration to `config/services.php` and the corresponding `.env`
   keys.
3. Change that one binding line.

Nothing in provisioning, delivery, retry handling, the commands or the database
changes. No architectural change is required — verified by the test suite, which
swaps the binding for a recording double and exercises the whole delivery path
through it.

---

## 7. Production settings still to be applied

Not deployed here. These are configuration changes, not code changes.

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | Preflight FAILS on `production` + `APP_DEBUG=true` |
| `APP_DEBUG` | `false` | Debug output would expose internals |
| `APP_URL` | the real HTTPS origin | Correct absolute URLs and cookie scope |
| `SESSION_SECURE_COOKIE` | `true` | The session cookie must never travel over plain HTTP |
| `SESSION_SAME_SITE` | `lax` (or `strict`) | CSRF defence in depth |
| `SESSION_ENCRYPT` | `true` (recommended) | Session payload at rest |
| `SESSION_DOMAIN` | the serving domain | Stops the cookie leaking to siblings |
| `SESSION_LIFETIME` | ≥ the longest expected sitting | 75 × 40s ≈ 50 min plus reading time; `120` is comfortable |
| `SESSION_DRIVER` | `database` | Already set; survives a restart mid-competition |
| `MAIL_*` / gateway keys | vendor values | See §6 |
| `DB_*` | the production database | Preflight verifies identity and isolation |

`session.http_only` is already `true` and must stay so.

Also confirm before competition day: HTTPS enforced at the web server, the
server clock synchronised via NTP (the exam is timed from it), and
`php artisan config:cache` run **after** the `.env` is final.

---

## 8. Competition-day sequence

```bash
# the night before
php artisan madad:preflight                     # expect PASS or WARNING, never FAIL
php artisan madad:provision                     # deliver credentials
php artisan madad:provision --retry-failed      # chase the failures
php artisan madad:preflight                     # confirm again

# on the morning
php artisan madad:status                        # read the state
php artisan madad:status --set=open             # confirm when asked

# while it runs (all read-only, safe at any moment)
php artisan madad:status
php artisan madad:preflight

# when it ends
php artisan madad:status --set=closed           # ENDS the competition; confirm
php artisan madad:preflight                     # integrity of the final data
php artisan madad:results --top=100 --export=madad-top100.csv
php artisan madad:results --top=0   --export=madad-all-completed.csv
```
