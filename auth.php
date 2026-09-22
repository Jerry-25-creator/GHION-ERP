<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function current_user(): ?array {
    if (empty($_SESSION['user'])) return null;
    return $_SESSION['user'];
}
function require_login(): void {
    if (!current_user()) { 
        header('Location: ' . url('index.php')); 
        exit; 
    }
}
function require_role(string ...$roles): void {
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../modules/403.php'; exit;
    }
}
function is_financial(): bool {
    $r = current_user()['role'] ?? '';
    return in_array($r, ['director', 'consultant'], true);
}
function can(string $permission): bool {
    $role = current_user()['role'] ?? '';
    if (in_array($role, ['director', 'consultant'], true)) return true;
    static $permissions = null;
    if ($permissions === null) {
        try {
            $stmt = db()->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role=? AND p.permission_key=?');
            $stmt->execute([$role, $permission]);
            $permissions = [$permission => (bool)$stmt->fetchColumn()];
        } catch (Throwable $e) {
            $permissions = [$permission => $role === 'accountant' && $permission === 'view_operational_reports'];
        }
    }
    return $permissions[$permission] ?? false;
}
function require_permission(string $permission): void {
    require_login();
    if (!can($permission)) {
        http_response_code(403);
        include __DIR__ . '/../modules/403.php';
        exit;
    }
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid CSRF token.');
    }
}
function audit(string $action, string $module, $recordId = null, $old = null, $new = null, $reason = null): void {
    $u = current_user();
    db()->prepare("INSERT INTO audit_log (user_id,user_name,action,module,record_id,old_value,new_value,reason,ip_address)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $u['id'] ?? null, $u['name'] ?? 'system', $action, $module,
            $recordId === null ? null : (string)$recordId,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            $reason, $_SERVER['REMOTE_ADDR'] ?? null
        ]);
}
