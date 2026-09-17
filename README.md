# Briqpay for Shopware 6

![Briqpay Logo](https://cdn.briqpay.com/static/images/briqpayLogo.svg)

Accept invoice, card, direct bank and BNPL payments in Shopware 6 through a
single Briqpay integration, for consumers and businesses alike.

[![CI](https://github.com/Briqpay-Extensions/briqpay-for-shopware/actions/workflows/ci.yml/badge.svg)](https://github.com/Briqpay-Extensions/briqpay-for-shopware/actions/workflows/ci.yml)

## Requirements

| | |
| --- | --- |
| Shopware | 6.6.x (tested on 6.6.10.6) |
| PHP | 8.2 – 8.5 |
| Account | A Briqpay merchant account ([sign up](https://briqpay.com)) |

## Installation

1. Download `BriqpayPayments-x.y.z.zip` from the latest GitHub release.
2. Either upload it in the administration under **Extensions → My extensions →
   Upload extension**, or unzip it into `custom/plugins/BriqpayPayments`.
3. Install and activate:

   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate BriqpayPayments
   ```

4. Open **Settings → System → Plugins → Briqpay Payments** and enter your
   **Client ID** and **Client Secret**.

**Test mode** is on by default; the plugin then talks to Briqpay's playground
environment, which uses separate credentials. Switch it off, and enter the
production credentials, when you are ready to take real payments.

Activation registers a payment method named *Briqpay* and assigns it to every
sales channel. The compiled administration bundle ships inside the plugin, so
no Node toolchain is needed to install it.

## How checkout works

Briqpay renders embedded on Shopware's checkout confirm page, so shoppers stay
inside the native checkout:

```
1 Cart                        Shopware
2 Sign in / register          Shopware
3 Confirm  – shipping method  Shopware
           – payment       →  Briqpay iframe
```

On the confirm page the plugin hides Shopware's payment method list, order
summary and *Place order* button, and renders the Briqpay checkout below the
product table. The iframe presents every payment method itself and the purchase
completes inside it.

Nothing is hidden or disabled up front: the shopper sees every payment method
immediately. Validation happens at Briqpay's **decision step**, the pause
after the shopper presses pay but before any money moves:

```
shopper presses "Complete purchase"
        │
        ▼
  Briqpay pauses ──► POST /briqpay/decision
        │
        ├─ session not the one tied to this cart?  → 403, nothing is decided
        ├─ cart empty?                             → reject, shopper notified in the iframe
        ├─ totals drifted?                         → reject, shopper notified in the iframe
        └─ otherwise                               → allow, payment proceeds
```

The plugin asks for that pause on every session it creates. At the decision
step it rebuilds the session payload from the live cart and compares the total
including VAT, in minor units, with what Briqpay holds for the session. Because
the endpoint is called from the shopper's browser, it will only ever decide on
the session id that Shopware itself stored for that shopper's cart. A posted
id that does not match is refused outright.

Because the checks run server-side they cannot be stepped around by editing the
page.

The plugin keeps the Briqpay session in step with the cart automatically. It
re-syncs when the confirm page loads, when the cart changes and when the context
switches (address, shipping, currency), and only sends an update when the
total, an address, the company or the cart reference has genuinely changed.
Switching between consumer and business (a business account, or a billing
address with a company name) starts a fresh session, since Briqpay treats the
two as different session types.

When payment completes, Briqpay redirects the shopper to `/briqpay/finalize`.
The plugin turns the cart into a Shopware order, tags it with the Briqpay
session id, records the payment method the shopper actually used (Swish,
Klarna, card, …) so it shows on the order instead of a generic *Briqpay*, writes
the order number back to the Briqpay session and sends the shopper to the
finish page. If the shopper closes the browser before returning, the
`order_status` webhook creates the order instead.

**Headless / Store API.** The Briqpay session, including the HTML snippet
that mounts the iframe, is exposed on the cart as the `briqpay` extension, and
a `redirectUrl` query parameter on the checkout request is carried through so
that `/briqpay/finalize` sends the shopper back to your own frontend with
`orderId` (or `error=1`) appended.

## Order management

Capture, refund and cancel are available from the **Briqpay Order Management**
card on the order detail page, for the full amount or a partial one. Every
action goes through the admin API under `/api/_action/briqpay/` (`capture`,
`refund`, `cancel`, `hosted-page`, `list/{transactionId}`), so it can be
scripted too.

| Action | How | Offered when |
| --- | --- | --- |
| Capture | **Capture** opens a modal listing every order line, with the remaining quantity per line pre-filled. Adjust quantities for a partial capture. | The transaction is `authorized` or `paid_partially`, and the captured total has not yet reached the order total. |
| Refund | **Refund** on a capture row opens a modal listing that capture's lines, with the not-yet-refunded quantity per line pre-filled. | The transaction is `paid`, `paid_partially` or `refunded_partially`. A refund always targets one capture and cannot exceed what that capture still holds. |
| Cancel | **Cancel order** releases the authorisation. | The transaction is `authorized` and nothing has been captured. Once anything is captured, refund instead. |

**Nothing is offered until Briqpay approves the order.** Capture, refund and
cancel act on an authorisation, so while the transaction is still `open` (or
held for manual review) the card says what it is waiting for and offers no
operations; the server refuses them too, whatever the page happens to show. The
operations appear once the `order_status` webhook reports the order approved.

Every capture, refund and cancellation is recorded locally with its Briqpay id
and listed on the card, with running totals, and the card refreshes itself
after each operation, so no page reload is needed. Actions are serialised per
transaction, so a double-click or two administrators acting at once cannot
fire two overlapping captures.

**Captures made outside Shopware are picked up automatically.** A PSP that
captures on authorisation, or a capture or refund entered in the Briqpay
dashboard, is recorded in the same ledger and moves the transaction state, so
the order shows as paid with its capture rather than offering a Capture button
for money that has already been taken.

The payment method on the order names the provider the shopper actually paid
with: *Nuvei | Briqpay Payments*, not the generic *Briqpay | Briqpay
Payments*.

The card **Briqpay Details** alongside shows the payment method (PSP), the
session id as a link into the Briqpay dashboard, and, where Briqpay returned
them, company details, PSP metadata and strong-authentication output.

### Transaction states

The plugin moves Shopware's payment transaction through these states:

| Event | Transaction state |
| --- | --- |
| Briqpay reports the order approved (`order_approved_not_captured`) | `authorized` |
| Capture covering the order total, or Briqpay confirms a capture | `paid` |
| Partial capture | `paid_partially` |
| Refund covering everything captured, or Briqpay confirms a refund | `refunded` |
| Partial refund | `refunded_partially` |
| Cancel from the order page, or Briqpay reports `order_cancelled` | `cancelled` |
| Briqpay rejects the order or a capture | `failed` |
| Session tagged `manual_review` by Briqpay | `reminded` (held for a human) |
| Transaction still `open` five hours after the order was created | `failed` |

Shopware has no dedicated "on hold" payment state, so `reminded` is used for
orders Briqpay has flagged for manual review: the transaction is parked there
rather than authorised or paid, and stays put until Briqpay's follow-up
webhook or an administrator moves it on.

### Amounts and rounding

Shopware prices gross by default, and a gross price rarely divides into a whole
number of minor units net: 19.99 at 19% VAT is 16.798… net. So the plugin never
rebuilds a line total from a per-unit price. Each cart line carries the total
Shopware charges for it and the VAT that total contains, converted to minor
units and nothing more, and the order total sent to Briqpay is exactly the sum
of the lines sent with it. A cart of several awkward prices, a percentage
discount and a paid shipping line therefore reconciles to the cent: Shopware's
order, the Briqpay session's total and the session's own cart all agree.

The per-unit price is derived from the line rather than the other way round,
and a capture takes its unit prices from the Briqpay session, so a capture is
always priced the way the session it is matched against is.

Discounts are ordinary cart lines with negative amounts, referenced by their
coupon code (`discount_SUMMER20`), so they can be captured and refunded like
any other line.

**The remaining tolerance.** Capturing part of a line still splits a total that
may not divide evenly. Two partial captures of a three-unit line can sum a few
minor units away from the whole. Briqpay treats such an order as fully
captured, so the plugin does too: a capture or refund counts as complete within
5 minor units of the target. The tolerance is bounded, so a genuinely
incomplete capture still shows as `paid_partially`.

## Payment links (hosted payment pages)

For orders taken by phone or email, or an order whose original payment attempt
with another method failed, the plugin can generate a Briqpay hosted payment
page and hand the customer a link.

1. Create the order in the administration as usual.
2. On the order detail page, open the **Briqpay Payment Link** card.
3. Click **Create payment link**.
4. Copy the link to the customer.

The page is titled with the order number, shows the order lines, and pre-fills
the billing and shipping address already on the order. The customer only picks
a payment method. When they pay, Briqpay redirects them to the shop's finish
page and the `order_status` webhook moves the existing order to its paid state,
and no second order is created.

The card appears for orders that have no Briqpay session yet, and stays on the
page once a link exists so it can be copied again later; **Create new link**
replaces the previous one. Both the card and the API refuse to create a link
once the order's transaction is `paid`, `paid_partially`, `refunded` or
`refunded_partially`, so a customer cannot be charged twice.

## Webhooks

Briqpay notifies the shop about order, capture and refund status changes. The
endpoint is registered automatically on every session the plugin opens:

```
https://your-shop.example.com/briqpay/webhook
```

It must be reachable from the public internet over HTTPS.

| Event | Statuses subscribed | What the plugin does |
| --- | --- | --- |
| `order_status` | `order_pending`, `order_approved_not_captured`, `order_rejected`, `order_cancelled` | Creates the order if it does not exist yet; moves the transaction to `authorized`, `failed` or `cancelled`; holds it in `reminded` when the session carries `manual_review`. |
| `capture_status` | `pending`, `approved`, `rejected` | `approved` → `paid`, `rejected` → `failed`, only if the `captureId` exists in the session's captures. |
| `refund_status` | `pending`, `approved`, `rejected` | `approved` → `refunded`, only if the `refundId` exists in the session's refunds. |

> **Note on webhook authentication.** This endpoint does not verify a request
> signature. It is written so that the request body is never trusted: only the
> session id is read from it, and the plugin then re-reads the authoritative
> session from Briqpay over the authenticated API before changing anything. An
> unauthenticated caller can therefore make the shop re-sync an order against
> Briqpay's own record, but cannot dictate what that record says.

If the session cannot be fetched from Briqpay, the plugin answers `502` and
acts on nothing, so Briqpay retries later. A `captureId` or `refundId` that
Briqpay's own record does not contain is logged and ignored.

**Duplicates and races.** Briqpay may deliver the same event more than once.
Each delivery claims a five-minute marker keyed on session, event type, status
and capture/refund id; a repeat within that window is answered `200` and
skipped. Order creation from a webhook and from `/briqpay/finalize` share a
per-session lock, so a shopper returning to the shop at the same moment the
webhook arrives cannot produce two orders. Locks live in the `briqpay_lock`
table, so they hold across PHP workers.

**Reconciliation.** A scheduled task (`briqpay.reconciliation`) runs every 30
minutes and fails any Briqpay transaction that is still `open` five hours after
it was created: an abandoned checkout whose follow-up webhook never came. It
takes the same per-session lock, skips anything a webhook is processing at that
moment, and never touches a transaction that has moved past `open`. As with
every Shopware scheduled task, it needs the scheduled-task runner and message
consumer to be running.

**Webhook base URL.** Briqpay is normally told to post webhooks to the sales
channel domain the shopper is browsing. When that address is not reachable from
the internet (behind a reverse proxy, or a tunnel in development), two
settings under **Briqpay - Webhook URL** change only the webhook address;
checkout and redirects keep the normal domain:

1. **Webhook base URL override**: a fixed base such as
   `https://shop-webhooks.example.com`. Used as-is when set.
2. **Webhook base URL discovery endpoint**: a URL the plugin polls for JSON
   containing `url` or `hostname` (the format of cloudflared's quick-tunnel
   metrics endpoint), so a tunnel whose hostname changes is picked up without
   editing settings. The result is cached for 60 seconds, and the last known
   hostname is kept if the endpoint is briefly unreachable. Ignored when the
   override is set.

## Settings

### Briqpay - General

| Setting | Default | Description |
| --- | --- | --- |
| Client ID | *(required)* | From **API credentials** in the Briqpay merchant portal. |
| Client Secret | *(required)* | Differs between playground and production. |
| Test mode | On | On sends requests to the Briqpay playground API; off moves real money. The credentials must match the selected environment. |
| Terms & conditions URL | *(empty)* | Linked from inside the Briqpay checkout. Falls back to the storefront's home page. |
| Verbose logging | Off | Also writes informational entries (webhook received, duplicate delivery ignored, reconciliation summaries) to the Shopware log. Warnings and errors are logged regardless. Enable only while troubleshooting, as these entries carry order and session identifiers. |

### Briqpay - Webhook URL (advanced / local development)

| Setting | Default | Description |
| --- | --- | --- |
| Webhook base URL override | *(empty)* | Fixed base URL Briqpay posts webhooks to, when it differs from the shop's own address. Leave empty in production unless you are behind a proxy. |
| Webhook base URL discovery endpoint | *(empty)* | URL polled for `{"url": "https://…"}` to find the current tunnel hostname. Ignored if the override is set. |

## Market: country, currency and locale

All three are read from Shopware, with nothing to configure in the plugin:

| Sent to Briqpay | Taken from |
| --- | --- |
| `country` | the customer's billing address, falling back to the sales channel's country |
| `currency` | the sales channel context's currency |
| `locale` | the locale code of the active language, e.g. `sv-SE` or `en-GB` |

For a hosted payment page the same three come from the order: its billing
address, its currency and its language.

### Business (B2B) sessions

A session is opened as `business` when the customer's account type is business
or their billing address carries a company name. The company block then holds
the company name, and Shopware's **VAT ID** as `vatNo` when the customer has
one.

`cin`, a national organisation number, is never sent: Shopware does not
collect one, and a placeholder would be treated as a real company identifier by
whatever reads the order next. A business session with only a company name is
valid.

**Currencies must use two decimals.** Every amount is sent to Briqpay in minor
units, and the plugin refuses to open a session for a currency whose rounding
is set to anything other than two decimal places (JPY, ISK, KWD, …) rather than
silently send an amount that is a factor of ten out.

This matters beyond presentation: **the market decides which payment methods a
shopper is offered.** A Swedish shop billing in EUR is not shown Swish; the same
shop in SEK is. If methods you expect are missing, check that the country,
currency and language agree before looking anywhere else. Because the country
follows the billing address, one sales channel selling into several countries
gets each customer's local methods without any per-market configuration.

## Extending the plugin

Two Symfony events fire at the points where a merchant may need to intervene.
Subscribe to them as you would to any Shopware event.

| Event | When | Modifiable |
| --- | --- | --- |
| `Briqpay\Payments\Event\BriqpaySessionPreCreateEvent` | After the session payload has been built and before it is sent: on session creation, on every session update, and when the expected total is recomputed at the decision step | `payload` via `setPayload()` |
| `Briqpay\Payments\Event\BriqpayDecisionEvent` | After the plugin's own decision checks, before the allow/reject answer is posted to Briqpay | `approve` via `setApprove()` |

Both carry the `Cart` and the `SalesChannelContext`. Payloads for hosted
payment pages do not pass through the first event.

## Development

```bash
composer install

composer test        # PHPUnit
composer analyse     # PHPStan
composer lint        # php-cs-fixer, dry run
composer lint:fix    # php-cs-fixer, apply
composer check       # all three
```

The unit suite runs on plain PHP with no Shopware install and no database:
every Shopware and Doctrine collaborator is a PHPUnit test double, so only the
Composer autoloader is needed.

A Docker-based development stack (Shopware 6.6, a tunnel for webhook testing
and browser tests) is not part of this repository.

### Building the administration assets

The compiled admin bundle in `src/Resources/public/administration/` is
committed so the plugin can be installed without a Node toolchain. Rebuild it
whenever anything under `src/Resources/app/administration/` changes:

```bash
# from the plugin root. Works on a host with shopware-cli installed,
# and equally inside the dev container, where it is preinstalled
shopware-cli extension build .

# then, from the Shopware root, refresh the copy under public/bundles/
bin/console assets:install
```

Shopware's own `bin/build-administration.sh` only refreshes the platform copy
under `public/bundles/`; it does **not** update the plugin's shipped assets.

### Layout

```
BriqpayPayments/
├── composer.json
├── src/
│   ├── BriqpayPayments.php        Plugin entry point: payment method registration
│   ├── Checkout/Capture/          `briqpay_capture` entity (capture / refund / cancel records)
│   ├── Components/                Cart, address and amount mapping; API credentials
│   ├── Controller/                finalize, webhook, decision; Api/ admin actions
│   ├── Event/                     BriqpaySessionPreCreateEvent, BriqpayDecisionEvent
│   ├── Migration/                 `briqpay_capture` and `briqpay_lock` tables
│   ├── Payment/                   Payment handler registered with Shopware
│   ├── ScheduledTask/             Reconciliation task and handler
│   ├── Service/                   Session, capture/refund/cancel, hosted page, locking,
│   │                              reconciliation, address sync, logging
│   ├── Subscriber/                Checkout session sync, Store API cart extension,
│   │                              PSP name on orders
│   └── Resources/
│       ├── app/administration/    Admin order-detail components (source)
│       ├── public/administration/ Compiled admin bundle (committed)
│       ├── config/                config.xml, services.xml, routes.xml
│       └── views/storefront/      Checkout confirm page override
└── tests/                         PHPUnit unit tests
```

## Releasing

Each release is tagged and published as a [GitHub
Release](https://github.com/Briqpay-Extensions/briqpay-for-shopware/releases),
with the plugin packaged as a downloadable zip.

## Support

- Documentation: <https://developer.briqpay.com/>
- Email: <hello@briqpay.com>

## License

[MIT](LICENSE)
