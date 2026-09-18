# 03 - Architecture Review

My review of the inherited architecture (`refund-architecture-overview.md`) and
my target-state extension.

## Ranking criteria

Each risk is ranked by blast radius: how much of the system fails, and for
whom, if the assumption behind the design turns out to be wrong. A risk that
can take down the shared admin request path or corrupt a financial record
outranks a risk that only degrades one screen's freshness or one report's
runtime, even if the latter is more visible day to day.

## Risks, ranked by blast radius

### 1. Synchronous Create Refund inside the admin request (highest)

Create Refund is called synchronously inside `RefundController::execute`,
right after the database write, with a 30-second timeout and two immediate
retries (sections 3 and 4). A slow or degraded ERP does not just slow one
refund: it holds a PHP-FPM worker for up to roughly 90 seconds per attempt,
and at a campaign peak of 25 submissions per minute that is enough concurrent
holds to exhaust the admin worker pool. At that point every admin function,
not just refunds, becomes unavailable. This is the only risk on this list
that threatens the whole Magento Admin rather than one feature inside it.

**Mitigation.** Decouple the ERP call from the admin request using the
outbox pattern already designed in `02-refund-design.md`: the controller
writes the refund and a single outbox row in the same transaction and
returns immediately (BR-06); a cron worker drains the outbox and makes the
ERP call off the request path; a slower reconciler cron is the backstop for
anything left retryable. This also directly fixes the "commit before notify"
requirement (BR-06) more robustly than a same-request try/catch does, since
the record and the outbox row are written atomically and the ERP call is
free to fail and retry without ever risking a worker-pool exhaustion event.

### 2. The "one active refund per order" invariant is enforced only in the application layer

Section 7 states plainly that v1 "leaves to the application layer" the
concurrency control behind BR-05: no two refunds may be created against the
same order simultaneously, and a resubmission after a timeout must not
create a second financial action. An application-layer check (read status,
decide, write) is a classic check-then-act race: two concurrent requests can
both read "no active refund" before either writes, and both proceed. Unlike
the other risks here, this one does not need volume or scale to trigger, two
operators working the same order, or one operator double-clicking Submit
during a slow response, both under everyday conditions rather than at
campaign peak. Because a refund is irreversible once submitted (BR-09), a
duplicate refund is a real financial error, not a display glitch, which is
why it ranks above the read-side risks below despite touching fewer rows.

**Mitigation.** Move the invariant into the database rather than the
application: a unique constraint (or a unique partial index) that makes a
second concurrent insert against the same `order_id` fail at the database
level when an active refund already exists, combined with the `version`
column the FRD already calls for (line 254) to detect a stale read on
resubmission or use SELECT..... FOR UPDATE query. The application-layer check can stay as a fast, user-friendly
rejection; the database constraint is what actually makes the invariant
hold under concurrency.

### 4. Per-row order/line reads in the worklist grid, and the two-second poll (lowest)

The worklist loads its refund collection and then re-reads each refund's
order and lines individually as it iterates (section 5), and both the
worklist and the entry screen poll the refund-status endpoint every two
seconds per session (section 2). At ≈40 concurrent operators this is real
load, but it degrades gracefully: request latency climbs, the screen feels
sluggish, and no data is lost or corrupted, which is why this sits at the
bottom of the ranking despite being the most frequently exercised path in
the system.

**Mitigation.** For the worklist, join the order and line summary data into
the grid's own select instead of reading them per row in the loop, the same
fix already called for against PR #2's `Worklist.php` in the code review.
For the poll, replace the current plan with a
push mechanism (long-poll or SSE) once the outbox/reconciler design from
`02-refund-design.md` exists, since the worklist would then only need to
learn that a background cron changed a status, not simulate real-time
tracking of an admin request in flight.

## A related risk not ranked above: duplicated presentation logic

Section 6 has the refund total and breakdown independently recomputed by
the receipt PDF, the order-details view model, order history, the
confirmation email, and the downstream export, five call sites deriving the
same figures from the same stored record. This is not ranked with the risks
above because nothing about it fails under load or concurrency; the risk is
drift between surfaces as the calculation evolves; exactly the class of bug
already found in the code review, where the receipt PDF's tax recalculation
dropped the first-party/third-party split that the canonical calculation in
`fromSnapshot` still had.

**Mitigation.** Fetch the breakdown through a single method that computes
the refund and tax figures once from the stored snapshot, and have each of
the five surfaces call that method rather than deriving the numbers itself.
This does not force the surfaces to share a rendering format: the PDF, the
storefront block, the email template, and the export mapper can each style
and structure the same underlying figures however their channel needs, so
each surface keeps its own presentation while the numbers themselves stop
being five independent implementations of the same arithmetic.

---

## Target-state extension

The changes above compose into one coherent next-phase design, largely
already specified in `02-refund-design.md`: the admin request only ever
touches the local database and returns immediately; an outbox worker cron
makes every ERP call off the request path, keyed for idempotency by
`refund_no` (create) and `erp_refund_id` (confirm) per BR-07/BR-08; a
slower reconciler cron is the backstop for anything the worker leaves
retryable; the one-active-refund-per-order invariant is enforced by a
database constraint rather than an application-layer check; the settlement
sweep runs against a composite index sized for the actual query it issues;
the worklist reads its order/line summary through the grid's own join
instead of per-row; and the five presentation surfaces call one shared
method for the refund/tax breakdown instead of recomputing it independently.

None of this is needed to make the happy path work; v1 already does that.
It is needed because the current design's own sign-off assumptions
(sections 5 and 7) are explicitly sized for today's volume and explicitly
flagged as the first things to feel pressure as volume and operator count
grow, and because two of the risks above (the concurrency invariant, and the
presentation drift already caught in the code review) are correctness gaps
rather than scaling concerns and do not need volume growth to surface.

### Testing strategy

Each fix above has a test that demonstrates the specific failure it closes,
not just a happy-path check:

- **Idempotency.** Replay Create Refund and Confirm Refund against the stub
  with the same key (`refund_no`, `erp_refund_id`) and assert no duplicate
  credit note and no duplicate confirmation, since this is the property the
  outbox worker and the reconciler both depend on to be safe to retry.
- **Stub failure modes.** Drive each documented stub failure (timeout, 429,
  5xx, business rejection) through the outbox worker and assert the correct
  sub-status lands (`retryable_error` versus `failed`), the exact branching
  PR #2 got wrong for the retryable-after-failure case.
- **Concurrency.** Fire two concurrent submissions at the same order and
  assert exactly one `mp_refund` row is created; this is the acceptance gate
  already written into the delivery plan in `02-refund-design.md`, and it is
  the test that actually proves the database constraint above works, not
  just that it was added.
- **Load.** Worklist first-page latency against a 40-concurrent-operator
  simulation, and settlement sweep runtime against a 20,000-row seed, both
  already named as acceptance thresholds in the delivery plan.
- **Chaos.** Kill the outbox worker mid-batch and confirm the reconciler
  closes out anything left retryable on its next run, proving the design
  tolerates a crash rather than only the case where nothing goes wrong.
- **Presentation consistency.** One seeded partial refund, checked against
  all five surfaces, asserting they show identical figures once they share
  the single breakdown method, replacing the "shared acceptance tests" that
  section 7 currently leans on to keep independently-derived figures
  aligned by convention alone.
