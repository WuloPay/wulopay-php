# Changelog

## 1.2.0
- Invoices (`$wulopay->invoices`: create with an idempotency key, all, retrieve, send, cancel) and payment links
  (`$wulopay->paymentLinks`: create, all, retrieve, cancel), for API v1.2.0. WuloPay works out invoice totals from the
  lines; money fields are rounded to cents before sending. Tested live against the sandbox.

## 1.1.0
- Subscriptions: `$wulopay->plans` (create, all, retrieve); sign-up sessions with `'mode' => 'subscription'` (amount and
  currency come from the plan); `$wulopay->subscriptions` (all, retrieve, cancel, pause, resume, charge with a required
  idempotency key, charges, createCardUpdateSession, createManageLink, sandbox advance).

## 1.0.0
- Requires PHP 8.3 or newer (8.2 reaches end of life on 31 December 2026).
- Checkout sessions: create (with Idempotency-Key), retrieve, cancel, refund.
- Access tokens fetched, cached (optionally in a shared cache) and refreshed automatically.
- Webhook signature verification (`Webhook::constructEvent`).
- Typed exceptions for 400, 401, 403, 404, 409, 429 and network errors.
