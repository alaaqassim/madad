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
| Exam data | question orders, order length, duplicate questions, foreign questions, answer length and alphabet, position range, answers ahead of position, **both timing anchors** (attempt anchor, question anchor, anchor ordering, anchor not in the future), not-started integrity, terminal position, completed aggregates |

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
php artisan madad:status --set=ready
```

With no `--set` it only reports: competition name, status, portal open,
`question_count`, `seconds_per_question`, `show_result`,
`exam_duration_minutes`, the availability window and whether the clock is
inside it right now, contestant totals and the `not_started` / `in_progress` /
`completed` split, plus the question-bank size.

There is no toggle. A change requires naming both the action and the target
value, so no shape of this command alters anything by accident. None of the
`madad:*` commands takes a competition id any more — there is one competition,
its configuration is the `competition_settings` singleton, and there is nothing
to select.

### Two conditions, not one

The portal is usable only when **both** are true:

* `status = open` — the operator's switch, set here;
* the server clock is inside `[starts_at, ends_at)` — the announced schedule.

A window that has already passed refuses contestants as **`competition_closed`**
even while `status` still reads `open`, because waiting cannot help them. Set
the window with a direct update to `competition_settings`; preflight reports it
back and warns when the time left is too short for a full paper.

### Two different clocks

`competition_settings.starts_at` / `ends_at` are the **global availability
window**: when anyone may use the portal. `exam_duration_minutes` (60) is the
**personal allowance** each contestant gets from their own Begin. A contestant's
attempt ends at the earlier of the two:

```
effective_end = min( competition_users.started_at + 60 minutes , ends_at )
```

So a contestant beginning at 10:15 against an 11:00 window gets 45 minutes, and
the ready screen tells them so before they press Begin. No end time is stored
anywhere — it is derived on every request from `started_at`.

There is a third clock, and it is per question: `seconds_per_question` (40),
counted from `competition_users.current_question_started_at`. Answering opens
the next question **immediately**, so that anchor is the moment the previous
answer landed — which is why it is stored rather than derived. A contestant who
answers quickly works through more questions in the same hour; they do not earn
extra minutes.

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

**Closing also SETTLES everyone left mid-exam.** Their exam is over, so they are
scored and closed — because every result surface filters on `completed`, and a
contestant left in progress would silently lose the answers they had already
given. Contestants whose own time had run out are recorded as ending when it
actually did; contestants cut short by the closure are recorded as ending at the
closure.

That settlement is **irreversible** — re-opening the competition does not undo
it. The command states this, names how many contestants it will score and close,
and then asks. A non-interactive run refuses unless `--force` is given — it never
silently closes.

---

## 3. `madad:provision` — accounts and credentials

> ⚠️ **Check `BCRYPT_ROUNDS` before the first run.** A bcrypt hash carries the
> cost it was made with, so lowering the setting afterwards changes nothing for
> accounts that already exist — and raising it does not protect them either.
> Cost 12 verifies four times slower than cost 10, and login is the only
> CPU-bound step in the system. See §8.

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

`email_status` is **derived, not stored**. There is no such column; it is read
from the two the delivery service writes together:

```
credentials_sent_at IS NOT NULL  ->  sent
email_attempts > 0               ->  failed   (sent already excluded)
otherwise                        ->  pending
```

In PHP use `$participation->email_status` or the `whereEmailStatus()` /
`whereEmailStatusNot()` query scopes. In raw SQL use
`CompetitionUser::EMAIL_STATUS_SQL`. `EmailStatusDerivationTest` checks the PHP
accessor, the SQL and both scopes against every combination of the two source
columns, so they cannot drift apart.

> `sent` means the gateway accepted the message. It does **not** mean the
> contestant received or read it — nothing here tracks real delivery.
Errors are listed with the gateway's message (capped at 20; the rest stay in
`competition_users.email_last_error`).

**No plaintext credential is ever written** — not to a table, not to a log, not
to this command's output. A retry generates a **new** password and replaces the
stored hash, because the old plaintext no longer exists to be resent. That is
safe: if the first delivery genuinely failed, the contestant never learned the
first password.

---

## 3b. `madad:settle` — leave nobody mid-exam

```bash
php artisan madad:settle --dry-run     # report only, change nothing
php artisan madad:settle               # settle those whose time has run out
php artisan madad:settle --all         # also settle contestants still in their time
php artisan madad:settle --force       # non-interactive
```

### The problem it solves

A contestant is settled by their own next request: the last answer finalises on
the spot, and a returning contestant whose time has run out is settled before
the gate. **Neither fires for someone who closes the browser at question 59 and
never comes back** — no request, no settlement.

Every result surface filters on `exam_status = completed`, so that contestant
disappears from their own result and from the Top 100 **while their answers sit
intact in the row**. On the development fixtures that was 100 contestants
holding 3,500 answers, nine of them scoring high enough for a place — including
one on 55, which would have been third.

The confirmed rule is that **at the end of the exam nobody is in progress**, and
each contestant is measured against the end of their own exam.

### What it does

Nothing new: each contestant is finalised by exactly the code their own final
request would have run — the score recomputed from the answer string, and
`completed_at` set to the moment the exam actually ended (the last answer, the
close of the last window, or the deadline). So a settled contestant enters the
duration tie-break on the same terms as everybody else.

`--dry-run` first, always. It prints how many would be settled and the highest
scores currently missing from the results, and changes nothing:

```
to settle: 100   (time run out: 100)

highest scores currently missing from the results:
+---------------------------+---------+----------+---------+
| email                     | correct | answered | reached |
+---------------------------+---------+----------+---------+
| contestant0174@madad.test | 55      | 56       | 59      |
```

**Settling is irreversible.** Without `--all` it is still always safe: it only
records something the rules already consider true. `--all` also settles
contestants whose time has *not* run out, which is only correct once the
competition itself is over — and `madad:status --set=closed` already does it.

---

## 3c. `madad_results` — reading the results straight from SQL

For anyone who queries the database directly rather than running the command —
the project manager, a committee, a reporting tool:

```sql
SELECT * FROM madad_top100;                              -- the winners
SELECT * FROM madad_results;                             -- everyone, ranked
SELECT * FROM madad_results WHERE contestant_email = ?;  -- one contestant's rank
```

Two views, two questions:

| | |
|---|---|
| `madad_top100` | **who won** — the first 100, nothing else |
| `madad_results` | **where is X ranked** — every completed contestant |

`madad_top100` is defined on top of `madad_results`, so the ranking lives in one
place and changing the tie-break changes both.

Looking one contestant up in `madad_results` returns their **true** rank even
though the `WHERE` runs outside the view: `ROW_NUMBER()` stops MariaDB merging
the view into the outer query, so the ranking is computed over the whole field
first and filtered afterwards. Asserted by test, not assumed.

A **view**, not a stored procedure: it composes (`WHERE`, `LIMIT`, `JOIN`), it
works in every GUI client without `CALL` syntax, and it takes no parameters.

| Column | |
|---|---|
| `rank` | 1, 2, 3 … computed by the view. Never stored. |
| `competition_user_id`, `contestant_name`, `contestant_email` | |
| `correct_answers`, `total_questions`, `answered_questions` | |
| `started_at`, `completed_at` | |
| `duration_seconds` | The tie-break, visible. |

### Why it exists

The ranking is no longer something anyone can write from memory: score DESC,
then the **shorter attempt**, then `id` for stability. Someone hand-writing that
`ORDER BY` and omitting the duration term gets a list that looks entirely
plausible and quietly ignores the tie-break. The view publishes the ordering
once so nobody has to reconstruct it.

### The drift guard

The view is a **second implementation** of a rule whose authority is
`ResultService`, so the two could drift the day the rule changes.
`ResultsViewTest` compares them row by row against data whose ties can only be
settled by duration. Verified: deleting the duration term from the view fails
three tests, one of them saying so in as many words. Change the rule in one
place and the suite stops you.

### What it deliberately does not expose

No `answers`, no `question_order`, no `user_id`, and nothing from `users` — so
no password hash can leave through it. Only the columns the CSV already
publishes. Asserted by test.

> **Run `madad:settle --dry-run` first.** The view filters on
> `exam_status = 'completed'`, so a contestant left mid-exam is absent from it
> exactly as they are absent from the CSV.

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
| `completed_at` | ISO 8601. The moment the exam **ended** — the last answer, the close of the last window, or the deadline — not the moment the server noticed. |
| `duration_seconds` | `completed_at − started_at`. **The tie-break made auditable**: without it nobody could check why the boundary fell where it did. |

No password, no hash, no answer key, no per-question detail, no internal secret.
A name beginning `=`, `+`, `-` or `@` is prefixed with a single quote so Excel
treats it as text instead of evaluating it as a formula.

> **Not `.xlsx`.** No spreadsheet package is installed and none was added. CSV
> with a BOM is what Excel needs for Arabic. A real `.xlsx` requirement would be
> a new decision with a new dependency behind it.

### Top 100 and the tie-break

```
1. correct_answers   DESC     the score
2. duration          ASC      completed_at − started_at — THE TIE-BREAK
3. id                ASC      stability only, never a ruling
```

**The confirmed rule: level on score, the faster attempt wins.** It is applied
as a secondary sort rather than only at the boundary, so it settles the case it
was asked for — the 101st matching the 100th — and stays consistent everywhere
else. `tie_break_rule` is `fastest_completion` in every payload.

**Duration, not finishing time.** Whoever begins later inside a window that is
open for hours is not slower for it, so what counts is the contestant's own
clock. A contestant who began at 10:00 and took 20 minutes beats one who began
at 09:00 and took 50, even though the second finished first by the wall clock.

`id ASC` remains a stability device for repeatable extractions, **not** a
ranking rule, and it must not be presented to the business as one.

The command shows how the boundary was settled:

```
cutoff: 5 contestants scored 37; separated by duration, and the last place
went to an attempt of 41:20.
```

### The one case still needing a human

If someone outside the list matches the last place on **both** score and
duration, the rule cannot separate them and the command refuses to pretend:

```
WARNING: Top-100 cutoff cannot be settled by the tie-break.
CUTOFF CONTESTED: 2 contestant(s) outside this list match the last place on BOTH
score (37) and duration (41:20). The faster-wins rule cannot separate them, so
the boundary of this list is NOT decided. A business ruling is required.
```

Duration is compared to the microsecond, so this is rare — but it is possible,
and nobody on an equal footing is silently discarded. Rank is never stored: it
is a property of a query, not of a contestant.

> **Why `completed_at` had to be made exact.** Finalisation is lazy: it happens
> on the first request after the exam is over. Recording `now()` at that point
> would have given a contestant who walked away at 09:50 and reopened the page
> at 11:30 a 2½-hour attempt — losing a tie they had won. Every finalising path
> now passes the real end instead.

---

## 5. `madad:import-questions`

```bash
php artisan madad:import-questions questions.csv
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
| `MailCredentialGateway` | Done. Sends the real Arabic RTL message. Bound automatically when `MAIL_MAILER` is not `log`/`array`. |
| `App\Mail\ContestantCredentials` + its Blade view | Done. Carries the credentials, the portal link, the opening time and the rules a contestant would otherwise learn by losing a question. |
| `CredentialDeliveryService` | Done. Provisions, dispatches, records `email_status`, `email_attempts`, `credentials_sent_at`, `email_last_error`. |
| Failure and retry handling | Done and tested, including re-issue-not-replay. |

### What remains external

**Only the provider's credentials.** The delivery path is complete and tested:
`MailCredentialGateway` sends a real Arabic RTL message through Laravel's mail
layer, and which gateway is bound follows `mail.default`:

```
MAIL_MAILER=log     → LogCredentialGateway    (records a dispatch, sends nothing)
MAIL_MAILER=array   → LogCredentialGateway    (tests)
anything else       → MailCredentialGateway   (really sends)
```

So attaching a provider is **one line in `.env`** and no code change at all. The
default falls toward the log gateway deliberately: the other way round, a
missing or misspelt `MAIL_MAILER` would send nothing while reporting success,
and nobody would find out until competition day.

The current setting is `MAIL_MAILER=log`, so **no mail is being sent** and none
is claimed.

### The password never rests anywhere

Generated, sent, forgotten. It is not stored, not logged, and not queued — the
mailable is sent synchronously rather than queued, because queueing serialises
the plaintext into the `jobs` table. That is why a retry re-issues rather than
resends: nothing can replay a password nobody kept.

### The exact seam

One binding, in `app/Providers/AppServiceProvider.php`, and it follows the
mailer rather than being set by hand:

```php
$this->app->bind(CredentialGateway::class, function (): CredentialGateway {
    return in_array(config('mail.default'), ['log', 'array', null], true)
        ? new LogCredentialGateway
        : new MailCredentialGateway;
});
```

Writing a DIFFERENT provider (an API client rather than SMTP) is:

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

## 7. Backups — manual, and the one hour that matters

There is no cron and no persistent process on the production server, so this is
a written procedure rather than an automated one. It is short because the
database is small: a full dump of the live data measured **372 ms** and **711
KB**.

### What is actually at risk

| When | What is lost | Recoverable? |
|---|---|---|
| Before the competition | the roster and the question bank | **Yes** — reload the roster file, rerun `madad:import-questions` |
| **During the hour** | **the answers of everyone who has begun** | **No. Ever.** |
| After the export | nothing that matters | Yes — the CSV holds the results |

Only the middle row is irreplaceable, and it is irreplaceable in a way worth
saying plainly: you cannot ask a thousand contestants to sit it again, and even
if you could, they have now seen the questions. The competition burns once.

The likeliest cause is not a failed disk. It is one SQL statement — and this
competition is operated from SQL:

```sql
UPDATE competition_users SET exam_status = 'completed';   -- WHERE forgotten
```

### Taking one

```bash
mysqldump -u root -p --single-transaction madad_prod > madad_2026-09-10_0930.sql
```

`--single-transaction` is not decoration. Without it, a dump taken while
contestants are answering reads one table at one instant and another table at a
different one, and produces a backup that is internally inconsistent while
looking perfectly fine. With it the dump is a consistent snapshot and blocks
nobody.

Two rules that are easy to skip and cost everything:

- **Do not leave it on the server's own disk.** A backup beside the thing it
  protects does not protect against losing the disk. Copy it off.
- **Name it with the time.** You will take several within one hour and will need
  to know which is the newest before the last one.

### Verifying one

A backup nobody checked is a belief, not a backup. Open the file and confirm it
is not empty and ends with:

```
-- Dump completed on 2026-09-10  9:30:00
```

### Restoring one

```bash
mysql -u root -p madad_prod < madad_2026-09-10_0930.sql
```

Know this command before you need it. The moment you need it is not the moment
to look it up.

### When

| Moment | Why |
|---|---|
| Before opening the portal | The clean point to return to. The most important one. |
| Every ~10 minutes while it runs | Caps the worst possible loss at ten minutes |
| Immediately on closing | Then `madad:results`, which is itself a second copy of the outcome |

---

## 8. Production settings still to be applied

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
| `APP_TIMEZONE` | `Asia/Baghdad` | Already set. MariaDB DATETIME carries no zone, so this IS the meaning of every stored time. **Do not change it once real data exists** — it reinterprets every stored date. |
| `DB_TIMEZONE` | leave unset | Derived from `APP_TIMEZONE` and pinned on every connection. MariaDB defaults to `time_zone=SYSTEM`, which on a Linux server usually means UTC while the application means Baghdad — and the results views compare `effective_end_at` against `NOW(3)` read from the database. Preflight blocks if the two clocks disagree. |
| `BCRYPT_ROUNDS` | `10` | Already set. Cost 12 is **four times slower** to verify (measured: 374 ms vs 92 ms on the development laptop; the ratio is stable, the absolute number is not). Login is the only CPU-bound thing in the system - the database part of it is 0.23 ms - and a thousand contestants signing in at the hour would queue on it. **A bcrypt hash carries its own cost, so this only affects hashes made after it is set: change it BEFORE `madad:provision`, never after.** |

`session.http_only` is already `true` and must stay so.

Also confirm before competition day: HTTPS enforced at the web server, the
server clock synchronised via NTP (the exam is timed from it), and
`php artisan config:cache` run **after** the `.env` is final.

---

## 9. Competition-day sequence

```bash
# the night before
php artisan madad:preflight                     # expect PASS or WARNING, never FAIL
php artisan madad:provision                     # deliver credentials
php artisan madad:provision --retry-failed      # chase the failures
php artisan madad:preflight                     # confirm again

# on the morning — --strict, so anything unresolved stops you
php artisan madad:preflight --strict
mysqldump -u root -p --single-transaction madad_prod > madad_BEFORE.sql
php artisan madad:status                        # read the state
php artisan madad:status --set=open             # confirm when asked

# while it runs (all read-only, safe at any moment)
php artisan madad:status
php artisan madad:preflight
mysqldump -u root -p --single-transaction madad_prod > madad_HHMM.sql   # every ~10 min

# when it ends
php artisan madad:status --set=closed           # ENDS the competition AND settles everyone; confirm
mysqldump -u root -p --single-transaction madad_prod > madad_AFTER.sql
php artisan madad:settle --dry-run              # confirm nobody is left mid-exam
php artisan madad:preflight                     # integrity of the final data
php artisan madad:results --top=100 --export=madad-top100.csv
php artisan madad:results --top=0   --export=madad-all-completed.csv
```

Copy every dump off the server as you take it (§7). A backup on the disk it is
protecting is not one.

### If the portal is closed with SQL rather than the command

Closing with `UPDATE competition_settings SET status = 'closed'` shuts the gate
and settles nobody, so rows stay `in_progress`. **The results are correct
regardless** — `madad_results` and `madad_top100` read `effective_end_at` and
include anybody whose time is up, settled or not (§3c) — but the rows themselves
will be out of date and preflight will say so. `php artisan madad:settle --all`
makes them honest.

Opening with SQL is safe with nothing to follow: nothing is cached, and the next
request reads the new status.
