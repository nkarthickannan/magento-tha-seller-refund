# 04 - Build Impact Note

## The defect this fixes

In `01-code-review.md`, the most serious finding in `review/pr-01-partial-refund-presentation`
was in `Model/Pdf/RefundReceipt.php`: the branch added a per-tax-rate breakdown to the reissued
receipt by recomputing tax independently of `RefundTotalCalculator`, instead of reusing the
figures it already returns. That recomputation both duplicated logic that already exists in one
place on purpose, and dropped the filter that keeps first-party (core) lines out of the seller
refund. The review's specific correction was that `preRefund()` - the calculator's private
method that computes the order's original seller totals - should itself be modified to return
the tax grouped by rate, so that logic exists once and every surface that needs a breakdown
reuses it instead of deriving its own. A second, related finding was that some amounts were
derived by dividing another stored value rather than read from the stored rate, which is exactly
the kind of operation that introduces silent rounding drift on a document that has to reconcile
to the store's other refund surfaces to the last unit
(`Test/Integration/SurfacesAgreeOnPureSellerOrderTest.php` exists specifically to catch that).

Neither branch was merged, so `main` never shipped that defect. But `main` also never had a
per-rate tax breakdown anywhere, and the FRD's `ja_JP` setting means real orders can mix the
standard 10% rate with the reduced 8% rate (or a tax-exempt line) in a single cart - the running
store's own seed data has exactly this mix. Without a breakdown, the receipt only ever showed one
blended consumption-tax figure, which does not tell a seller how much tax was refunded at which
rate. This change adds that breakdown on both the pre-refund and the refund side, doing exactly
what the review asked: `preRefund()` now computes its own grouped tax amounts, and the refund
side reuses the same grouping code rather than a second, independent implementation.

## What changed

- `Model/Total/RefundTaxGroup.php` (new): an immutable value object holding one tax rate's
  taxable amount and tax amount, plus a static `group()` helper that folds `(rate, code,
  taxable, tax)` rows into one group per rate. This is the single grouping implementation;
  nothing else re-derives it.
- `Model/Total/RefundTotalCalculator.php`: `preRefund()` now accumulates a `tax_groups` entry
  (via `RefundTaxGroup::group()`) in the same loop that already computes the scalar `tax` total
  for each seller line - no new pass over the data, no new lookups. `assemble()` threads that
  through to `RefundFigures`.
- `Model/Total/RefundFigures.php`: added a `preRefundTaxGroups` property (set once, from
  `preRefund()`) and a `taxGroups()` method for the refund side, which maps the already-computed
  `lines` into the same `RefundTaxGroup::group()` call. `toArray()` is deliberately **not**
  changed - see below.
- `Model/Pdf/RefundReceipt.php`: `buildData()` exposes `pre_refund_tax_groups` and
  `refund_tax_groups` as their own keys, and `render()` prints each breakdown under its matching
  "Pre-refund Total" / "Refund" section.

Nothing about the existing `subtotal` / `shipping` / `tax` / `grand_total` figures changed, and no
schema or stored data changed - this only groups figures that were already being computed from
data already stored on `RefundItem` (`tax_rate`, `tax_amount`).

## A design decision worth flagging: why the breakdown is not inside `toArray()`

My first pass put `tax_groups` as a key nested inside the existing `pre_refund` / `refund`
sub-arrays of `RefundFigures::toArray()`. That broke
`SurfacesAgreeOnPureSellerOrderTest::testAllSurfacesAgreeOnRefundFigures`, which asserts the
receipt's `refund` totals are byte-for-byte identical to the sync export's, the storefront
block's, the order-history view model's, and the confirmation email's - and the email builds its
`refund_totals` by hand rather than through `toArray()`, so it never gained the new key. Rather
than touch four unrelated surfaces just to keep a key in sync that only the receipt needs, I
pulled the breakdown back out of `toArray()` entirely. `pre_refund` and `refund` keep the exact
four keys every surface already agrees on; the breakdown is exposed only via
`RefundFigures::preRefundTaxGroups` / `taxGroups()` directly, and only `RefundReceipt` reads them
(as its own top-level `pre_refund_tax_groups` / `refund_tax_groups` keys, not nested inside the
totals). This keeps the fix bounded to the one surface that needed it.

## Why this is safe

- `taxGroups()` and `preRefund()`'s grouping are pure folds over data the calculator already
  produces; they cannot disagree with the scalar totals, because they never touch the database or
  re-derive anything the scalar totals didn't already compute.
- `SurfacesAgreeOnPureSellerOrderTest.php` still passes unmodified - the shape every other
  consumer of `RefundFigures` depends on did not change.
- No controller, ACL, schema, or ERP contract change is involved.

## Tests added

- `Test/Unit/Model/Total/RefundTotalCalculatorTest.php` -
  `testPreRefundTaxGroupsByRateAtOrderedQuantity`: two seller lines at two different rates, at
  their full ordered quantity (not the refunded quantity), come back as two separate pre-refund
  groups - this is the literal case the review asked `preRefund()` to handle.
- `Test/Unit/Model/Total/RefundFiguresTest.php` - six cases: refund-side grouping (same rate
  folds together, different rates stay separate and ordered, allocated shipping counts toward
  the taxable amount, zero lines produce zero groups), plus two contract-guard cases: pre-refund
  groups are independent of the refund lines, and `toArray()` deliberately keeps its four-key
  shape (a regression test for the break described above).
- `Test/Unit/Model/Pdf/RefundReceiptTest.php` - asserts `RefundReceipt::buildData()` surfaces
  both breakdowns correctly and that `pre_refund` / `refund` keep the exact shape the other
  surfaces rely on.

Before this change, all of the above fail: `preRefund()` returns no `tax_groups` key,
`RefundFigures` has no `preRefundTaxGroups` property or `taxGroups()` method, and `buildData()`
has neither breakdown key. After it, all pass.

Run:

```bash
bin/assignment-test unit --filter 'RefundTotalCalculatorTest|RefundFiguresTest|RefundReceiptTest'
```

or the full bounded suite:

```bash
bin/assignment-test all
```

All 37 unit tests, all 7 integration tests (including `SurfacesAgreeOnPureSellerOrderTest`), and
the smoke suite pass with this change in place. I also rendered the receipt for a real refund
through the running web container: the store's own seed order has a tax-exempt line alongside a
10%-rate line, and the pre-refund breakdown correctly reports them as two separate groups rather
than one blended tax figure.

## What I deliberately left out

- I did not port any other part of either PR branch (the retry-handling changes, the ERP tax-code
  mapping, or the worklist columns). Each of those is a separate, already-working area of `main`
  today; folding them in was out of scope for a single bounded fix.
- I did not change the ERP payload (`Model/Erp/PayloadBuilder.php`) to send the grouped
  breakdown. The FRD's Create Refund contract takes one tax entry per line, and I did not want to
  extend the ERP contract as a side effect of a receipt-only fix.
