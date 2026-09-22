<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'failed', 'message' => 'POST is required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$uuid = trim((string)($input['uuid'] ?? ''));
$target = (string)($input['target'] ?? '');
$payload = $input['payload'] ?? null;
$originalCreatedAt = (string)($input['created_at'] ?? '');

$allowedTargets = [
    '/modules/customers.php',
    '/modules/inventory.php',
    '/modules/payroll.php',
    '/modules/production.php',
    '/modules/purchases.php',
    '/modules/sales.php',
];
$formNames = [
    '/modules/customers.php' => ['customer', 'payment', 'edit_customer'],
    '/modules/inventory.php' => ['product', 'edit_product', 'raw_material', 'edit_raw_material', 'receipt', 'stocktake'],
    '/modules/payroll.php' => ['employee', 'run'],
    '/modules/production.php' => ['run', 'edit_run'],
    '/modules/purchases.php' => ['supplier', 'edit_supplier', 'receipt', 'edit_purchase'],
    '/modules/sales.php' => ['sale', 'quick_payment', 'edit_sale'],
];

if (!preg_match('/^[a-f0-9-]{16,80}$/i', $uuid) || !in_array($target, $allowedTargets, true) || !is_array($payload)) {
    http_response_code(422);
    echo json_encode(['status' => 'failed', 'message' => 'Invalid synchronization request.']);
    exit;
}

$submittedForm = null;
foreach ($payload as $pair) {
    if (is_array($pair) && ($pair[0] ?? null) === 'form') {
        $submittedForm = (string)($pair[1] ?? '');
        break;
    }
}
$financialForms = [
    '/modules/payroll.php' => ['rates', 'approve_payroll'],
    '/modules/purchases.php' => ['price', 'edit_price'],
];
$allowedForms = $formNames[$target] ?? [];
if (isset($financialForms[$target]) && in_array($submittedForm, $financialForms[$target], true)) {
    if (!is_financial()) {
        http_response_code(403);
        echo json_encode(['status' => 'failed', 'message' => 'This transaction requires financial access.']);
        exit;
    }
    $allowedForms = array_merge($allowedForms, $financialForms[$target]);
}
if (!in_array($submittedForm, $allowedForms, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'failed', 'message' => 'This form is not eligible for offline synchronization.']);
    exit;
}

$pdo = db();
$existing = $pdo->prepare('SELECT status FROM sync_queue WHERE uuid = ?');
$existing->execute([$uuid]);
$existingStatus = $existing->fetchColumn();
if (in_array($existingStatus, ['synced', 'pending'], true)) {
    echo json_encode(['status' => 'duplicate', 'uuid' => $uuid]);
    exit;
}

$encodedPayload = json_encode([
    'target' => $target,
    'payload' => $payload,
    'created_at' => $originalCreatedAt,
    'user_id' => current_user()['id'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($existingStatus === 'failed') {
    $pdo->prepare("UPDATE sync_queue SET payload=?, status='pending', attempts=attempts + 1 WHERE uuid=?")
        ->execute([$encodedPayload, $uuid]);
} else {
    $pdo->prepare("INSERT INTO sync_queue (uuid, payload, status, attempts) VALUES (?, ?, 'pending', 1)")
        ->execute([$uuid, $encodedPayload]);
}

$previousRequestMethod = $_SERVER['REQUEST_METHOD'];
$previousPost = $_POST;
$previousUri = $_SERVER['REQUEST_URI'] ?? '';

try {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $target;
    $_POST = [];
    $formParts = [];
    foreach ($payload as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0]) || !is_string($pair[1])) {
            throw new RuntimeException('Malformed form payload.');
        }
        $formParts[] = rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]);
    }
    parse_str(implode('&', $formParts), $_POST);
    $_POST['csrf'] = csrf_token();

    ob_start();
    include __DIR__ . $target;
    $moduleOutput = ob_get_clean();

    if (http_response_code() >= 400 || str_contains($moduleOutput, 'Access Restricted') || str_contains($moduleOutput, 'alert-error')) {
        throw new RuntimeException('The queued transaction was rejected by the target module.');
    }

    $pdo->prepare("UPDATE sync_queue SET status='synced' WHERE uuid=?")->execute([$uuid]);
    try {
        $pdo->prepare("INSERT INTO sync_logs (transaction_uuid, direction, status, message, original_created_at, synchronized_at, user_id) VALUES (?, 'upload', 'accepted', ?, ?, NOW(), ?)")
            ->execute([$uuid, 'Transaction applied by ' . $target, $originalCreatedAt ?: null, current_user()['id']]);
    } catch (Throwable $ignored) {
        // sync_logs is supplied by the additive schema migration.
    }

    echo json_encode(['status' => 'accepted', 'uuid' => $uuid]);
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    $pdo->prepare("UPDATE sync_queue SET status='failed' WHERE uuid=?")->execute([$uuid]);
    try {
        $pdo->prepare("INSERT INTO sync_logs (transaction_uuid, direction, status, message, original_created_at, synchronized_at, user_id) VALUES (?, 'upload', 'failed', ?, ?, NOW(), ?)")
            ->execute([$uuid, substr($e->getMessage(), 0, 255), $originalCreatedAt ?: null, current_user()['id']]);
    } catch (Throwable $ignored) {
        // sync_logs is supplied by the additive schema migration.
    }
    http_response_code(422);
    echo json_encode(['status' => 'failed', 'uuid' => $uuid, 'message' => $e->getMessage()]);
} finally {
    $_SERVER['REQUEST_METHOD'] = $previousRequestMethod;
    $_SERVER['REQUEST_URI'] = $previousUri;
    $_POST = $previousPost;
}
