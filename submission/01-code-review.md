# 01 - Code Review

Your review of the two supplied branches, `review/pr-01-partial-refund-presentation`
and `review/pr-02-erp-refund-sync`, against `main`. Present your findings and
recommendations in whatever form conveys them best.

## PR #1: Align partial-refund presentation and validation

Overall verdict: changes required.

### `app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php`

- **Line 37**: The tax amounts should not be recalculated here, since the full
  calculation already exists inside the `fromSnapshot` method. The `preRefund()`
  method should be modified to return the grouped tax amounts so that logic can
  be reused instead of duplicated. Separately, the current calculation is
  missing a condition that checks whether an item is first-party or third-party,
  and that check needs to be added back.
- **Line 228**: The amount should be fetched from the stored tax rate rather than
  derived by dividing another value, because division can introduce accuracy
  problems.

### `app/code/Acme/SellerRefund/view/adminhtml/web/js/refund-form.js`

- **Line 109**: Removing the in-flight validation is incorrect. Without it, the
  CS operator can submit the button multiple times while the HTTP call is still
  in progress.
- **Line 129**: The call to `renderServerState` was already handling multiple
  cases, so removing it means those cases are no longer handled.
- **Line 130**: The `.fail()` handler should not have been removed, since it is
  required to handle client-side failures.

## PR #2: Add ERP tax mapping and refund retry handling

### `app/code/Acme/SellerRefund/Block/Adminhtml/Refund/Worklist.php`

- **Line 45**: Order and item data are already included in the select via the
  `joinOrderSummary()` and `addLineSummary()` methods. Loading the order and
  items again inside the `foreach` loop will cause serious performance issues.

### `app/code/Acme/SellerRefund/Model/Erp/PayloadBuilder.php`

- **Line 42**: `refund_no` must not change between requests. It needs to stay
  the same so the ERP does not receive multiple requests for the same refund.
- **Line 59**: `toOptionId()` returns Magento's internal option ID, not the
  actual tax code. Per the FRD, both the ERP and the store should use the tax
  code only.

### `app/code/Acme/SellerRefund/Service/RefundProcessor.php`

- **Line 142**: Collapsing the multiple catch blocks into a single catch, and
  simply marking the refund request as failed, is incorrect, since it removes
  the ability to retry the operation.
- **Line 292**: An operation should not be retryable once its status reflects a
  business failure. The condition added in this `match()` incorrectly allows a
  FAILED operation to be retried and needs to be removed.
- **Line 130**: This check is valid and should be retained, since it verifies
  whether the refund has already been created in the ERP.

### `app/code/Acme/SellerRefund/Model/RefundValidator.php`

- **Line 62**: This change needs to be reverted, since partial refunds are
  allowed.

### `app/code/Acme/SellerRefund/Controller/Adminhtml/Refund/Save.php`

- **Line 48**: This `beginTransaction()` call defers the local save until its
  matching `endTransaction()` call runs. As a result, the ERP call ends up
  executing before the local save completes, which is the wrong order and
  needs to be corrected.
