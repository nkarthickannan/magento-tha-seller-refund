# 06 - Questions and Next Steps

My open questions, the assumptions I made, where I deliberately stopped, and
what I would do next.

## Proposed contract enhancements to raise with the ERP owner

These are changes to the ERP Refund API itself, not to this module, so they
are not built into `02-refund-design.md`. The contract in Section 9 is
supplied as fixed, and the ERP is a system owned elsewhere, so anything below
is a question for whoever owns that side, not a decision I made unilaterally.

**Fold the pre-confirm status check into Confirm Refund.** Today, closing a
refund costs two calls: Read Refund Status to confirm the credit note is
still `refund-pending`, then Confirm Refund to close it (Section 9.2, 9.3).
At the settlement scan's stated scale of up to twenty thousand rows a day
(Section 12.1), that is up to forty thousand outbound calls where twenty
thousand would do, and each pair is also two chances to hit the rate limit
described in Section 9.6 instead of one. If Confirm Refund verified the
credit note's state internally before closing it, we would halve that call
volume and reduce our exposure to 429s during the daily sweep.

I would only accept this change on the ERP side under one condition: it has
to return a response that still lets us tell apart four outcomes, confirmed,
already confirmed (which BR-08 already treats as idempotent success), not yet
consistent and worth retrying, and genuinely rejected. The existing two-call
shape gives us that distinction for free, because the read happens before
anything is mutated. A merged call has to preserve it explicitly, or we risk
treating the eventual-consistency window right after Create Refund, the case
Section 9.2 already calls out as expected to happen and safe to retry, as a
hard business rejection instead.

I would also ask how the ERP intends to handle that same consistency lag
internally. If it fails fast when not yet consistent, the retry loop simply
moves from our side to theirs and the round-trip saving mostly disappears in
exactly the case it is meant to help with. If it blocks the request until its
own internal state catches up, our timeout budget for that call now has to
absorb their internal processing time, which is a dependency I would want
stated explicitly rather than discovered under load.
