# WuloPay PHP library

Take card payments on WuloPay's secure payment page from any PHP application. Needs PHP 8.3 or newer and the `curl` and `json` extensions. We recommend PHP 8.4 or 8.5 on their
latest patch release: older releases have critical security flaws (e.g. "PHP2Shell", fixed in 8.3.36, 8.4.27 and
8.5.12 on 21 October 2026).

```
composer require wulopay/wulopay-php
```

## Take a payment

```php
$wulopay = new \WuloPay\Client(getenv('WULOPAY_KEY_ID'), getenv('WULOPAY_SECRET'));

$session = $wulopay->checkoutSessions->create([
    'amount'    => 149.99,
    'currency'  => 'ZAR',
    'reference' => (string) $order->id,             // your order id
    'description' => 'Order ' . $order->id,
    'returnUrl' => 'https://shop.example.co.za/checkout/return',
    'cancelUrl' => 'https://shop.example.co.za/cart',
    'metadata'  => ['orderKey' => $order->key],
], 'order-' . $order->id);                          // Idempotency-Key: retries make no duplicates

header('Location: ' . $session['url']);             // WuloPay's payment page
```

The shopper pays on WuloPay's page (card details and 3-D Secure are handled there) and comes back to your
`returnUrl` with `?session_id=cs_…&status=paid`. **Don't trust the redirect**: confirm on your server.

```php
$session = $wulopay->checkoutSessions->retrieve($_GET['session_id']);
if ($session['status'] === 'paid' && $session['reference'] === (string) $order->id) {
    // mark the order paid
}
```

## Webhooks

Add a webhook endpoint in the WuloPay merchant portal (Product Configuration, Webhooks) and keep its signing secret.

```php
try {
    $event = \WuloPay\Webhook::constructEvent(
        file_get_contents('php://input'),            // the raw body, unchanged
        $_SERVER['HTTP_WULOPAY_SIGNATURE'] ?? '',
        getenv('WULOPAY_WEBHOOK_SECRET')
    );
} catch (\WuloPay\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

switch ($event['type']) {
    case 'checkout.session.completed':
        $session = $event['data']['object'];         // status "paid", payment, metadata
        // mark the order paid (ignore an event id you already handled)
        break;
    case 'checkout.session.expired':
        break;
}
http_response_code(200);
```

Events: `checkout.session.completed`, `checkout.session.expired`, `checkout.session.payment_failed`,
`refund.succeeded`, `refund.failed`. Retries run for about 3 days, so handle each event id once.

## Refunds and cancelling

```php
$wulopay->checkoutSessions->refund($sessionId);                 // everything still refundable
$wulopay->checkoutSessions->refund($sessionId, 20.00, 'Damaged'); // part of it
$wulopay->checkoutSessions->cancel($sessionId);                 // an unpaid session
```

## Subscriptions

Create a plan once, then send each new subscriber to a sign-up session. The shopper sees the terms, agrees, passes the
bank's check and pays the first charge (or nothing, during a free trial). The card is stored on WuloPay's side; you only
keep the subscription id.

```php
$plan = $wulopay->plans->create(['name' => 'Gold monthly', 'amount' => 49, 'interval' => 'month', 'trialDays' => 14]);

$signup = $wulopay->checkoutSessions->create([
    'mode' => 'subscription',
    'subscription' => ['planId' => $plan['id'], 'billingMode' => 'wulopay'], // WuloPay charges every month
    'reference' => $customerId,
    'customer' => ['email' => $email, 'name' => $name],                      // required: receipts and notices
    'returnUrl' => 'https://shop.example.co.za/account',
]);
header('Location: ' . $signup['url']);
// webhook subscription.created: $event['data']['object']['id'] is the subscription id
```

**Two billing modes**

- `wulopay`: WuloPay charges on the plan's schedule, retries failed payments (after 1, 3 and 5 days), e-mails the
  shopper and gives them a page to update the card or cancel. You follow along by webhook
  (`subscription.charge_succeeded`, `subscription.charge_failed`, `subscription.past_due`, `subscription.cancelled`, ...).
- `merchant` (default): your system decides when to charge, e.g. a WooCommerce Subscriptions renewal. Set `maxAmount`
  (the most a later charge may be, shown to the shopper) on the sign-up session, then:

```php
$charge = $wulopay->subscriptions->charge($subscriptionId, 'renewal-' . $orderId, 49);
if ($charge['status'] === 'failed' && $charge['retryAllowed'] === false) {
    // the card network says not to retry this card: send the shopper to save a new one
    $update = $wulopay->subscriptions->createCardUpdateSession($subscriptionId, 'https://shop.example.co.za/account');
}
```

The same idempotency key never charges twice. Also: `subscriptions->all($status)`, `retrieve`, `cancel($id, atPeriodEnd: true)`,
`pause`, `resume`, `charges`, `createManageLink` (a link to the shopper's own subscription page). In the sandbox,
`subscriptions->advance($id)` makes the next scheduled charge happen now, and a plan of R 60.00 always declines.

## Errors

Every error extends `WuloPay\Exception\WuloPayException` (`getMessage()` is the API's explanation,
`getHttpStatus()` the status): `InvalidRequestException` (400), `AuthenticationException` (401),
`PermissionException` (403, the key lacks a scope), `NotFoundException` (404), `ConflictException` (409),
`RateLimitException` (429, see `getRetryAfter()`), `ApiConnectionException` (network; safe to retry with the
same Idempotency-Key).

## Tokens

The client gets an access token with your key and secret, keeps it for its lifetime and renews it when needed.
In applications where each request is a new PHP process, pass a shared cache so tokens are reused:

```php
$wulopay = new \WuloPay\Client($keyId, $secret, ['tokenCache' => new MyRedisTokenCache()]); // implements TokenCacheInterface
```

## Sandbox and live

The client talks to the sandbox (`https://sandbox.wulopay.com/api/v1`) by default. Test cards are in the
[API specification](https://github.com/WuloPay/wulopay-openapi#sandbox). WuloPay gives you the live URL when your
account goes live: `new \WuloPay\Client($keyId, $secret, ['baseUrl' => '…'])`.

## Security

Keep the key, secret and webhook signing secret on your server (environment variables or a secrets store), never
in code you commit, a web page or a mobile app. Report security problems as described in [SECURITY.md](SECURITY.md).

## Development

```
composer install
vendor/bin/phpunit
```

MIT licensed.
