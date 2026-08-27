# Performance measurement

Three scripts that measure what the exam actually costs. They drive the real
application - real routes, real middleware, real session, real login - and time
every query Laravel executes. Nothing here is an estimate.

Their reason to exist is comparison: run them on a laptop, run them again on the
server, and the difference between the two reports is what the hardware was
worth. Run them after a change and the difference is what the change was worth.

## Safety

Every script takes the target database as its first argument and refuses to run
unless the connection really is that database. There is no default. They also
refuse outright when `APP_ENV=production`.

This matters because they write. `journey.php` sits a whole exam as a real
contestant; `per-page.php` submits one answer. Both put the contestant back to
`not_started` afterwards, and `round-trip.php` drops the scratch table it makes -
but do not point any of them at data you care about.

They pick the first contestant with `account_status=created` and
`exam_status=not_started`, and sign in with the development fixture password.
Override it with `MADAD_PERF_PASSWORD` if yours differs.

## The scripts

### `journey.php` - what a whole exam costs

```
php scripts/perf/journey.php madad_dev
```

One contestant from opening the page to logging out, every question answered.
Reports queries and milliseconds per step, then the totals.

### `per-page.php` - which query, exactly

```
php scripts/perf/per-page.php madad_dev
```

Every query on every endpoint, one line each with its own time, flagged at 5ms.
This is what finds a slow statement rather than a slow page.

### `round-trip.php` - why a query costs what it costs

```
php scripts/perf/round-trip.php madad_test
```

Separates the fixed round trip from the per-row work, and a plain write from a
write inside a shared transaction. Answers whether the bottleneck is the query,
the network, or the disk sync at commit - three problems with three different
fixes.

Give this one a test database: it creates and drops a table.

## Reading the reports

Queries are split three ways, and the split is the point:

| Column | What it is |
|---|---|
| بيانات المسابقة | The work the exam needs: settings, contestant, questions, recording answers |
| جلسة الدخول | Keeping the contestant logged in - the `sessions` table |
| تحديد معدّل الطلبات | The rate-limit counter that stops password guessing and request floods - the `cache` table |

The last two are framework overhead, not exam logic. When they dominate, the fix
is moving them out of the database rather than touching any query.

All figures are milliseconds.

## What the numbers do and do not include

Query time comes from Laravel's own `QueryExecuted` event: the round trip to the
database, the execution, and the result coming back. It does not include turning
those rows into Eloquent models.

Request time is measured around the HTTP kernel, so it covers all of PHP and all
of the queries - but not the web server and not the network between browser and
server. A contestant's browser will always see more than this number.

Verify no profiler is loaded before trusting a run. Xdebug in particular inflates
everything:

```
php -m | grep -Ei 'xdebug|blackfire|pcov'
```
