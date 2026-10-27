# Changelog

## 1.0.0
- Checkout sessions: create (with Idempotency-Key), retrieve, cancel, refund.
- Access tokens fetched, cached (optionally in a shared cache) and refreshed automatically.
- Webhook signature verification (`Webhook::constructEvent`).
- Typed exceptions for 400, 401, 403, 404, 409, 429 and network errors.
