<?php
// GHION ERP - Database Connection (PDO)
define('DB_HOST', 'localhost');
define('DB_NAME', 'ghion_erp');
define('DB_USER', 'root');
define('DB_PASS', '');

define('COMPANY_NAME', 'GHION INVESTMENTS AND ENTERPRISE LTD');
define('APP_NAME', 'GHION ERP');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function h($s): string { 
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); 
}

function base_path(): string {
    static $base = null;
    if ($base === null) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_ends_with($scriptDir, '/modules')) {
            $scriptDir = substr($scriptDir, 0, -8);
        }
        $base = rtrim($scriptDir, '/');
    }
    return $base;
}

function url(string $path = ''): string {
    $path = '/' . ltrim($path, '/');
    return base_path() . $path;
}

function money($amount, int $decimals = 0): string {
    if ($amount === null || $amount === '') return '0';
    return number_format((float)$amount, $decimals);
}

