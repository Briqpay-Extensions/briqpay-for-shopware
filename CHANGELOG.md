# Changelog

All notable changes to the Briqpay Payments plugin for Shopware 6 are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-09-18

### Fixed
- The terms URL sent to Briqpay's checkout now falls back to the shop's own configured Terms of Service page (`core.basicInformation.tosPage`, the same page Shopware's native checkout links to) before falling back further to the storefront home page. Previously it went straight to the home page when no override was set.
- Webhook requests whose `sessionId` isn't shaped like a real Briqpay session id are rejected immediately, without the outbound API call the plugin would otherwise make to verify it.

### Changed
- Documentation clarifies that the 5-minor-unit capture/refund tolerance only affects which transaction state is shown (`paid` vs `paid_partially`); the amount recorded for every capture and refund is always exact.

## [1.0.0] - 2026-09-17

First public release.

### Added
- Briqpay V3 checkout integration (embedded checkout on the Shopware checkout page) with support for B2C and B2B sessions.
- Order management from the Shopware administration: full and partial **capture**, full and partial **refund**, and **cancel** (cancel is refused once anything has been captured).
- **Hosted page** generation from the order detail view for pay-by-link / re-payment flows.
- Webhook handling with a compensating control instead of signature verification: every webhook re-fetches the session from the Briqpay API and verifies capture/refund IDs and manual-review flags against it before acting.
- Webhook deduplication and per-session locking via a database-backed lock table (`briqpay_lock`) so concurrent webhooks, finalize calls and admin actions cannot race.
- Manual-review handling: sessions flagged `manual_review` are moved to the "reminded" transaction state instead of being marked paid.
- Reconciliation scheduled task (every 30 minutes) that fails transactions that stayed `open` for more than 5 hours.
- Address sync: billing/shipping data returned by Briqpay is written back onto the Shopware order addresses (only non-empty fields).
- Webhook base URL override and discovery (`webhookBaseUrl` / `webhookBaseUrlSource`) so local tunnels (e.g. Cloudflare quick tunnels) can receive callbacks during development.
- Country selection for the Briqpay session is taken from the customer's billing address rather than the sales-channel default country.
- Currency precision guard: sessions are refused for currencies that do not use two decimals.
- PHPUnit test suite covering capture/refund/cancel logic, rounding tolerance, webhook verification, decision-controller authorization, address sync, hosted page, reconciliation and request mapping.

### Fixed
- Plugin migrations are now located flat in `src/Migration/` so Shopware actually runs them (they were previously nested and never executed).
- Orders paid through Briqpay are now stored with the Briqpay payment method (previously the sales-channel default method could remain on the order).
- Redirect and webhook URLs are built from the domain the customer actually used instead of the first configured domain of the sales channel.
- Rounding differences of up to 5 minor units between Shopware and Briqpay totals no longer leave orders in `paid_partially` / `refunded_partially` when Briqpay reports full capture/refund.
- Admin capture modal no longer shows a phantom free-shipping row or a stale total when the quantity is changed.
- The "Verbose logging" setting now actually controls logging: informational entries are written only when it is on, warnings and errors always.
- The payment-link card stays visible after a link has been created so the merchant can still copy it after the order page reloads.

### Fixed — amounts and rounding

- **Every cart line now carries Shopware's own line total.** Line totals were rebuilt from a per-unit net price rounded to whole minor units, which cannot reproduce the total a gross-priced shop charges: 19.99 at 19% VAT has a net value of 16.798…, and rounding that before multiplying by the quantity loses fractions that accumulate across lines. On a cart of three products, a 13% discount and a paid shipping line the shopper was billed 940.53 for an order Shopware had recorded as 940.52.
- **The order total sent to Briqpay is now exactly the sum of the cart sent with it.** The two were computed different ways, so a shipping line whose total came from Shopware (7.99) was counted as 7.98 in the order total — the payload contradicted itself before it was ever sent.
- **Discounts and promotions are sent as cart lines.** A promotion reached Briqpay referenced by its internal UUID, so any later capture failed with `CART_ITEM_NOT_FOUND`. Discounts now travel as an ordinary negative line referenced by their coupon code (`discount_SUMMER20`), matching the WooCommerce integration.
- **Captures and refunds are priced from the Briqpay session.** Briqpay rejects a capture whose unit price differs from the session's, and a partial capture priced as a share of a line does not always round back to the same per-unit figure — capturing 2 of 3 units of a 630.27 line gives 168.08 a unit where the session says 168.07.
- Variant product numbers (`SWDEMO10005.1`) are normalised consistently in the administration and on the server, so a capture of a variant matches the session line.

### Fixed — order management

- **A capture made outside Shopware now appears by itself.** A PSP that captures on authorisation, or a capture or refund made in the Briqpay dashboard, is recorded in the plugin's ledger and moves the transaction state. Previously a merchant had to press Capture for an order whose money had already been taken.
- **Capturing the remainder of a partially captured order works.** Shopware's transaction state machine reaches `paid` from `paid_partially` through the `pay` action, not the `paid` action; the order was left on "partially paid" with an `IllegalTransitionException` in the log while Briqpay reported the order as fully captured.
- **Order management waits for Briqpay's approval.** Capture, refund and cancel are refused — in the administration and on the server — until the `order_status` webhook reports the order approved. The card explains what it is waiting for, and says so separately for an order held for manual review.
- **The order page updates itself after an operation.** Cancelling or capturing left the buttons as they were until the page was reloaded; the card now reads the transaction state back from the server and asks the order page to reload.
- Only operations that would succeed are offered: refund appears once something is captured, cancel only on an uncaptured authorisation.
- An order that is already paid is no longer pulled back to `authorized` by a late approval webhook.

### Fixed — administration

- **The order detail page names the provider the shopper actually paid with** ("Nuvei | Briqpay Payments"), instead of the generic "Briqpay | Briqpay Payments". Shopware's payment-method field is a read-only select bound to an entity it fetches without the order in scope, so for a Briqpay order it is replaced with the recorded provider.

### Changed

- **`cin` is never sent as a placeholder.** A made-up organisation number of `0000000000` was sent for every business session without a VAT id, which downstream systems would treat as a real company identifier. Shopware's VAT ID is now sent as `vatNo`, and a business session with neither carries only the company name.
- Empty billing and shipping objects are omitted rather than sent as `[]`, which Briqpay refused with "body.data.billing is the wrong type".
- A completed session is not updated again (`SESSION_ALREADY_COMPLETED`), and no session is opened for an empty cart.

### Security
- Decision endpoint now only accepts the Briqpay session ID that belongs to the caller's own sales-channel context.
- Test decision subscriber that auto-approved sessions has been removed.
