# Changelog

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
