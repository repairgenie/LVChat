<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

// SaaS module engine test. Exercises plan CRUD, entitlement resolution,
// overrides, lifecycle (grace → downgrade), the platform fee, and the core
// service gates against a scratch SQLite DB.
// Usage: CHAT_DB=/tmp/opencode/saas.db php tests/saas_test.php

putenv('CHAT_DB=' . (getenv('CHAT_DB') ?: '/tmp/opencode/saas-test.db'));
$dbPath = getenv('CHAT_DB');
if (file_exists($dbPath)) {
    unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');
}

putenv('CHAT_MODULES=' . dirname(__DIR__) . '/modules');
putenv('SAAS_DEVELOPER_MODE=1');
putenv('SAAS_PLATFORM_FEE=0.75');
putenv('SAAS_PLATFORM_FEE_DESTINATION=acct_dev123');

require dirname(__DIR__) . '/src/bootstrap.php';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['passed']++;
        echo "  ok  $label\n";
    } else {
        $GLOBALS['failed']++;
        echo "FAIL  $label  $detail\n";
    }
}

function actor(int $id, string $role = 'user'): array {
    return ['id' => $id, 'username' => 'u' . $id, 'role' => $role, 'guest' => 0];
}

$mod = ModuleLoader::get('saas');
check('saas module boots', $mod !== null, json_encode(ModuleLoader::warnings()));

// Seed the users the tests reference (fresh scratch DB has none).
$pdo = Database::instance();
$u = $pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
foreach ([
    ['u1', 'u1@test.local', 'user'],
    ['u2', 'u2@test.local', 'admin'],
    ['u3', 'u3@test.local', 'user'],
    ['u4', 'u4@test.local', 'user'],
    ['u5', 'u5@test.local', 'user'],
    ['u6', 'u6@test.local', 'user'],
    ['u7', 'u7@test.local', 'user'],
    ['u8', 'u8@test.local', 'user'],
    ['u9', 'u9@test.local', 'user'],
    ['u10', 'u10@test.local', 'user'],
] as [$name, $email, $role]) {
    $u->execute([$name, $email, password_hash('x', PASSWORD_BCRYPT), $role]);
}

// ── Free plan + master switch semantics ─────────────────────────────────────
$free = SaaSService::freePlan();
check('free plan seeded', (int) $free['id'] > 0, (string) ($free['id'] ?? 'missing'));
check('free plan is undeletable', !SaaSService::deletePlan((int) $free['id'])['ok']);

check('disabled switch → everything unlimited', SaaSService::limit(actor(1), 'connections') === null);
check('disabled switch → features allowed', SaaSService::feature(actor(1), 'meetings') === true);

config_set('saas_enabled', '1');
check('enabled switch → free limits apply', SaaSService::limit(actor(1), 'connections') === 3);
check('enabled switch → meetings off on free', SaaSService::feature(actor(1), 'meetings') === false);
check('enabled switch → voice off on free', SaaSService::feature(actor(1), 'voice') === false);
check('enabled switch → bots off on free', SaaSService::feature(actor(1), 'openclaw_bots') === false);
check('enabled switch → admins exempt', SaaSService::limit(actor(2, 'admin'), 'connections') === null);
check('enabled switch → admins have features', SaaSService::feature(actor(2, 'admin'), 'meetings') === true);

// ── Plan CRUD ───────────────────────────────────────────────────────────────
$res = SaaSService::createPlan([
    'name' => 'Pro',
    'price_amount' => '499',
    'billing_cycle' => 'monthly',
    'feature_meetings' => '1',
    'feature_voice' => '1',
    'feature_openclaw_bots' => '1',
    'limit_connections' => '50',
    'limit_owned_channels' => '100',
    'limit_memberships' => '',
    'qos_voice_bitrate' => '64000',
]);
check('create plan ok', $res['ok'], json_encode($res));
$proId = (int) ($res['id'] ?? 0);
$pro = SaaSService::plan($proId);
check('plan saved with features', $pro && $pro['features'] !== '{}', (string) ($pro['features'] ?? ''));
check('duplicate slug rejected', !SaaSService::createPlan(['name' => 'Pro'])['ok']);

// ── Assignment + resolution order ───────────────────────────────────────────
SaaSService::assignPlan(1, $proId, 'admin');
$lim = SaaSService::limitsFor(actor(1));
check('assigned plan overrides free limits', $lim['limits']['connections'] === 50, json_encode($lim['limits']));
check('memberships unlimited (blank)', $lim['limits']['memberships'] === null);
check('paid plan has meetings', SaaSService::feature(actor(1), 'meetings') === true);
check('paid plan voice QoS applied', SaaSService::voiceQos(actor(1))['bitrate'] === 64000);

// ── Overrides (assign features regardless of plan) ──────────────────────────
config_set('saas_enabled', '1');
SaaSService::setOverride(3, 'meetings', '1', 'granted by support');
check('override grants feature off-plan', SaaSService::feature(actor(3), 'meetings') === true);
SaaSService::setOverride(3, 'connections', '5', 'custom cap');
check('override raises a limit', SaaSService::limit(actor(3), 'connections') === 5);
SaaSService::setOverride(3, 'connections', '', 'unlimited');
check('override can mean unlimited', SaaSService::limit(actor(3), 'connections') === null);
SaaSService::clearOverride(3, 'connections');
check('override cleared → free default returns', SaaSService::limit(actor(3), 'connections') === 3);

// ── Lifecycle: grace then downgrade ─────────────────────────────────────────
SaaSService::assignPlan(4, $proId, 'admin', ['period_end' => gmdate('Y-m-d H:i:s', time() - 60)]);
SaaSService::resolveLifecycle(4);
$row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 4');
check('lapsed period → grace', ($row['status'] ?? '') === 'grace', json_encode($row));
$row2 = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 4');
Database::query('UPDATE saas_user_plans SET grace_until = datetime("now", "-1 minute") WHERE user_id = 4');
SaaSService::resolveLifecycle(4);
$row3 = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 4');
check('grace lapsed → expired', ($row3['status'] ?? '') === 'expired', json_encode($row3));
$row2 = null; $row3 = null;
check('expired assignment counts as free', SaaSService::limit(actor(4), 'connections') === 3);

// ── Platform fee ────────────────────────────────────────────────────────────
check('platform fee enabled by default', SaaSService::platformFeeEnabled());
check('platform fee is $0.75', SaaSService::platformFeeAmountCents() === 75);
check('destination read from env', SaaSService::platformFeeDestination() === 'acct_dev123');
check('dev mode on', SaaSService::developerMode());
config_set('saas_platform_fee_enabled', '0');
check('dev mode toggle disables fee', SaaSService::platformFeeEnabled() === false);
config_set('saas_platform_fee_enabled', '1');
check('dev mode toggle re-enables fee', SaaSService::platformFeeEnabled() === true);

// ── Core service gates ──────────────────────────────────────────────────────
// Owned-channel cap (free plan = 10): give user 5 the free plan and count.
Database::query('INSERT INTO channels (name, slug, owner_id) VALUES ("#a", "a", 5)');
SaaSService::setOverride(5, 'owned_channels', '1', 'test');
$res = ChannelService::create(actor(5), '#b');
check('owned-channel cap enforced', is_string($res) && str_contains($res, 'channel limit'), is_string($res) ? $res : 'created');
SaaSService::clearOverride(5, 'owned_channels');

// Support ticket cap.
SaaSService::setOverride(6, 'open_tickets', '0', 'test');
$res = SupportService::create(actor(6), 'hello', '<p>world</p>');
check('support ticket gate (0 = disabled)', !$res['ok'] && str_contains($res['error'], 'not available'), json_encode($res));
SaaSService::clearOverride(6, 'open_tickets');

// OpenClaw bot gate + count (feature AND count must both allow it).
$res = OpenClawBotService::create('TestBot', 'hi', '', actor(7));
check('bots off on free plan', !$res['ok'] && str_contains($res['error'], 'not available'), json_encode($res));
SaaSService::setOverride(7, 'openclaw_bots', '1', 'test');
SaaSService::setOverride(7, 'openclaw_bot_count', '1', 'test');
$res = OpenClawBotService::create('TestBot', 'hi', '', actor(7));
check('bots allowed via overrides', $res['ok'], json_encode($res));
$res = OpenClawBotService::create('TestBot2', 'hi', '', actor(7));
check('bot count cap enforced', !$res['ok'] && str_contains($res['error'], 'bot limit'), json_encode($res));
SaaSService::clearOverride(7, 'openclaw_bots');
SaaSService::clearOverride(7, 'openclaw_bot_count');

// Upload size cap (chat uploads only).
$res = UploadService::validate(['error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024, 'name' => 'x.png', 'tmp_name' => '/nonexistent'], 'upload', actor(8));
check('upload size validated against plan default 5MB', !$res['ok'] && str_contains($res['error'], 'too large'), json_encode($res));
SaaSService::setOverride(8, 'upload_max_bytes', '10485760', 'test');
$res = UploadService::validate(['error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024, 'name' => 'x.png', 'tmp_name' => '/nonexistent'], 'upload', actor(8));
check('override raises upload cap', $res['ok'] === false && !str_contains($res['error'], 'too large'), json_encode($res));
SaaSService::clearOverride(8, 'upload_max_bytes');

// ── BillingService plumbing (no live provider) ──────────────────────────────
check('no provider configured yet', !BillingService::configured('stripe'));
check('unknown provider webhook rejected', BillingService::handleWebhook('nope', '{}', [])['ok'] === false);
$res = BillingService::createCheckout($pro, actor(1), 'stripe');
check('checkout fails when unconfigured', !$res['ok']);

// ── Global connection ceiling helper ────────────────────────────────────────
config_set('saas_max_connections_global', '200');
check('global ceiling default 200', SaaSService::globalConnectionCeiling() === 200);

// ── Webhook flow (hermetic; no live provider network calls) ─────────────────
putenv('SAAS_PLATFORM_FEE_DESTINATION='); // skip live Stripe transfers
config_set('stripe_secret_key', 'sk_test_x');
config_set('stripe_webhook_secret', 'whsec_testsecret');
$whsec = 'whsec_testsecret';

$makeStripeEvent = function (array $obj, string $type) use ($whsec): array {
    $event = ['id' => bin2hex(random_bytes(8)), 'type' => $type, 'data' => ['object' => $obj]];
    $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
    $ts = time();
    $sig = hash_hmac('sha256', $ts . '.' . $payload, $whsec);
    return [$payload, "t=$ts,v1=$sig"];
};

// checkout.session.completed → activate user 9 + platform fee recorded.
[$payload, $header] = $makeStripeEvent([
    'payment_status' => 'paid',
    'subscription' => 'sub_abc',
    'payment_intent' => 'pi_abc',
    'amount_total' => 499,
    'currency' => 'usd',
    'metadata' => ['user_id' => '9', 'plan_id' => (string) $proId],
], 'checkout.session.completed');
$res = BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => $header]);
check('stripe checkout webhook ok', !empty($res['ok']), json_encode($res));
check('checkout activates plan', SaaSService::limit(actor(9), 'connections') === 50);
$pay = Database::row('SELECT * FROM saas_payments WHERE user_id = 9 AND kind = "payment"');
check('payment recorded', $pay !== null && (int) $pay['amount'] === 499, json_encode($pay));
$fee = Database::row('SELECT * FROM saas_payments WHERE user_id = 9 AND kind = "platform_fee"');
check('platform fee recorded ($0.75)', $fee !== null && (int) $fee['amount'] === 75, json_encode($fee));

$payCount = (int) Database::scalar('SELECT COUNT(*) FROM saas_payments WHERE user_id = 9');
BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => $header]);
$payCount2 = (int) Database::scalar('SELECT COUNT(*) FROM saas_payments WHERE user_id = 9');
check('webhook idempotent (no double payment)', $payCount === $payCount2);

$res = BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => 't=' . (time() + 10) . ',v1=deadbeef']);
check('bad stripe signature rejected', empty($res['ok']), json_encode($res));

// invoice.paid renewal — arrives with no user id, resolved via subscription.
Database::query('UPDATE saas_user_plans SET period_end = datetime("now", "-1 day") WHERE user_id = 9');
[$payload, $header] = $makeStripeEvent([
    'subscription' => 'sub_abc',
    'payment_intent' => 'pi_abc2',
    'amount_paid' => 499,
    'currency' => 'usd',
    'lines' => ['data' => [['period' => ['end' => time() + 30 * 86400]]]],
], 'invoice.paid');
$res = BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => $header]);
$row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 9');
check('invoice.paid renews by subscription id', !empty($res['ok']) && ($row['status'] ?? '') === 'active' && ($row['period_end'] ?? '') > gmdate('Y-m-d H:i:s'), json_encode($res));

[$payload, $header] = $makeStripeEvent(['subscription' => 'sub_abc'], 'invoice.payment_failed');
BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => $header]);
$row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 9');
check('payment_failed → grace', ($row['status'] ?? '') === 'grace', json_encode($row));
[$payload, $header] = $makeStripeEvent(['id' => 'sub_abc', 'status' => 'canceled'], 'customer.subscription.updated');
BillingService::handleWebhook('stripe', $payload, ['stripe-signature' => $header]);
$row = Database::row('SELECT * FROM saas_user_plans WHERE user_id = 9');
check('subscription cancelled → downgrade', ($row['status'] ?? '') === 'expired', json_encode($row));

// BTCPay InvoiceSettled (invoice-per-cycle model).
config_set('btcpay_webhook_secret', 'btcsec');
$event = ['deliveryId' => 'del-1', 'type' => 'InvoiceSettled', 'invoice' => ['id' => 'inv-1', 'metadata' => ['user_id' => '10', 'plan_id' => (string) $proId], 'amount' => 4.99, 'currency' => 'USD']];
$payload = json_encode($event, JSON_UNESCAPED_SLASHES);
$sig = 'sha256=' . hash_hmac('sha256', $payload, 'btcsec');
$res = BillingService::handleWebhook('btcpay', $payload, ['btcpay-sig' => $sig]);
check('btcpay settled webhook ok', !empty($res['ok']), json_encode($res));
check('btcpay activates plan', SaaSService::limit(actor(10), 'connections') === 50);
$res = BillingService::handleWebhook('btcpay', $payload, ['btcpay-sig' => 'sha256=' . str_repeat('0', 64)]);
check('bad btcpay signature rejected', empty($res['ok']), json_encode($res));

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
exit($GLOBALS['failed'] > 0 ? 1 : 0);
