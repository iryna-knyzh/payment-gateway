<?php

declare(strict_types=1);

/*
 * Provider simulator for local development: pretends to be FakeBank and QuickPay.
 * A plain PHP router script, deliberately NOT part of the Symfony app - in real
 * life providers are external systems.
 *
 *   php -S localhost:8081 simulator/index.php
 *
 * Behaviour by amount (minor units):
 *   ...13  (e.g. 1013 = 10.13)  -> declined (insufficient funds)
 *   ...42  (e.g. 1042 = 10.42)  -> responds after 12 s => the gateway times out
 *   anything else               -> pending; finish it with /_control/complete
 *
 * Control endpoint (plays the provider's back office):
 *   POST /_control/complete?payment_id=<uuid>&result=success|failure[&duplicate=1]
 *   -> changes the status and sends a signed webhook to the gateway.
 */

$config = [
    'fakebank_api_key' => getenv('FAKEBANK_API_KEY') ?: 'fb_dev_api_key',
    'fakebank_webhook_secret' => getenv('FAKEBANK_WEBHOOK_SECRET') ?: 'fb_dev_webhook_secret',
    'quickpay_merchant_id' => getenv('QUICKPAY_MERCHANT_ID') ?: 'merchant_dev',
    'quickpay_secret' => getenv('QUICKPAY_SECRET') ?: 'qp_dev_secret',
];

$stateFile = sys_get_temp_dir().'/payment-simulator-state.json';

/** @return array<string, array<string, mixed>> payments keyed by gateway payment id */
function load_state(string $file): array
{
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

/** @param array<string, array<string, mixed>> $state */
function save_state(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, \JSON_PRETTY_PRINT), \LOCK_EX);
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function qp_sign(array $fields, string $secret): string
{
    return hash_hmac('sha256', implode('|', $fields), $secret);
}

function amount_suffix(int $minor): int
{
    return $minor % 100;
}

/** Sends a webhook to the gateway; returns the HTTP status line. */
function send_webhook(string $url, string $body, array $headers): string
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    @file_get_contents($url, false, $context);

    return $http_response_header[0] ?? 'no response';
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$state = load_state($stateFile);

// ---------------------------------------------------------------- FakeBank (JSON)
if ('POST' === $method && '/fakebank/v1/charges' === $path) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer '.$config['fakebank_api_key']) {
        respond(401, ['error' => ['message' => 'Invalid API key']]);
    }

    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $amount = (int) ($in['amount'] ?? 0);
    $ref = (string) ($in['merchant_reference'] ?? '');

    if ($amount <= 0 || '' === $ref) {
        respond(422, ['error' => ['message' => 'amount and merchant_reference are required']]);
    }

    $charge = $state[$ref] ?? [
        'provider' => 'fakebank',
        'id' => 'fb_'.bin2hex(random_bytes(6)),
        'merchant_reference' => $ref,
        'amount' => $amount,
        'status' => 13 === amount_suffix($amount) ? 'DECLINED' : 'PENDING',
        'decline_reason' => 13 === amount_suffix($amount) ? 'insufficient_funds' : null,
        'callback_url' => (string) ($in['callback_url'] ?? ''),
    ];
    $state[$ref] = $charge;
    save_state($stateFile, $state);

    if (42 === amount_suffix($amount)) {
        sleep(12); // the charge IS created, but the gateway gives up waiting
    }

    respond(201, $charge);
}

if ('GET' === $method && preg_match('#^/fakebank/v1/charges/by-reference/([0-9a-f-]{36})$#', $path, $m)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer '.$config['fakebank_api_key']) {
        respond(401, ['error' => ['message' => 'Invalid API key']]);
    }

    isset($state[$m[1]]) ? respond(200, $state[$m[1]]) : respond(404, ['error' => ['message' => 'Charge not found']]);
}

// ---------------------------------------------------------------- QuickPay (form + codes)
if ('POST' === $method && '/quickpay/api/pay' === $path) {
    $f = $_POST;
    $signed = [$f['merchant_id'] ?? '', $f['order_id'] ?? '', $f['amount'] ?? '', $f['currency'] ?? ''];

    if (!hash_equals(qp_sign($signed, $config['quickpay_secret']), (string) ($f['signature'] ?? ''))) {
        respond(200, ['result_code' => '901', 'message' => 'Bad signature']); // yes, HTTP 200
    }

    $minor = (int) round(((float) $f['amount']) * 100); // the simulator may be sloppy; the gateway may not
    $orderId = (string) $f['order_id'];
    $declined = 13 === amount_suffix($minor);

    $state[$orderId] ??= [
        'provider' => 'quickpay',
        'transaction_id' => 'QP'.random_int(100000000, 999999999),
        'order_id' => $orderId,
        'result_code' => $declined ? '051' : '100',
        'message' => $declined ? 'Insufficient funds' : 'Pending',
        'callback_url' => (string) ($f['callback_url'] ?? ''),
    ];
    save_state($stateFile, $state);

    if (42 === amount_suffix($minor)) {
        sleep(12);
    }

    $p = $state[$orderId];
    respond(200, ['transaction_id' => $p['transaction_id'], 'result_code' => $p['result_code'], 'message' => $p['message']]);
}

if ('POST' === $method && '/quickpay/api/status' === $path) {
    $f = $_POST;

    if (!hash_equals(qp_sign([$f['merchant_id'] ?? '', $f['order_id'] ?? ''], $config['quickpay_secret']), (string) ($f['signature'] ?? ''))) {
        respond(200, ['result_code' => '901', 'message' => 'Bad signature']);
    }

    $p = $state[(string) $f['order_id']] ?? null;
    null === $p
        ? respond(200, ['result_code' => '404', 'message' => 'Order not found'])
        : respond(200, ['transaction_id' => $p['transaction_id'], 'result_code' => $p['result_code'], 'message' => $p['message']]);
}

// ---------------------------------------------------------------- Back office: finish a payment + send webhook
if ('POST' === $method && '/_control/complete' === $path) {
    $id = (string) ($_GET['payment_id'] ?? '');
    $success = 'failure' !== ($_GET['result'] ?? 'success');
    $times = isset($_GET['duplicate']) ? 2 : 1;

    if (!isset($state[$id])) {
        respond(404, ['error' => 'Unknown payment_id']);
    }

    $p = &$state[$id];
    $eventId = 'evt_'.bin2hex(random_bytes(8));

    if ('fakebank' === $p['provider']) {
        $p['status'] = $success ? 'APPROVED' : 'DECLINED';
        $p['decline_reason'] = $success ? null : 'do_not_honor';
        $body = json_encode(['event_id' => $eventId, 'type' => 'charge.updated', 'charge' => $p]);
        $headers = [
            'Content-Type: application/json',
            'X-FakeBank-Signature: '.hash_hmac('sha256', $body, $config['fakebank_webhook_secret']),
        ];
    } else {
        $p['result_code'] = $success ? '000' : '005';
        $p['message'] = $success ? 'Approved' : 'Do not honor';
        $fields = [
            'notification_id' => $eventId,
            'order_id' => $p['order_id'],
            'transaction_id' => $p['transaction_id'],
            'result_code' => $p['result_code'],
        ];
        $fields['signature'] = qp_sign($fields, $config['quickpay_secret']);
        $fields['message'] = $p['message'];
        $body = http_build_query($fields);
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
    }

    save_state($stateFile, $state);

    $deliveries = [];
    for ($i = 0; $i < $times; ++$i) {
        $deliveries[] = send_webhook($p['callback_url'], $body, $headers);
    }

    respond(200, ['webhook_event_id' => $eventId, 'callback_url' => $p['callback_url'], 'deliveries' => $deliveries]);
}

respond(404, ['error' => 'Not found: '.$method.' '.$path]);
