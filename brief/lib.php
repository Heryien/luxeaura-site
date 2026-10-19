<?php
// Shared helpers for the Luxe Aura brief form. No secrets live in this file: the
// client access code and the admin password hash are read from a config file
// OUTSIDE the web root, created once on the server (see anti-slop/audit-012).
// Data (answers, history, uploads) is stored next to that config, also outside
// the web root, so the 5-minute "git reset --hard" deploy never touches it and
// nothing is reachable by URL.

declare(strict_types=1);

const MAX_FILE_BYTES = 25 * 1024 * 1024;
const ALLOWED_EXT = ['jpg','jpeg','png','webp','heic','gif','pdf','svg','ai','eps','psd','doc','docx','zip'];

function brief_home(): string {
    // web root is <home>/luxeaura.mujiso.com, this file is <web root>/brief/lib.php
    $env = getenv('LUXE_BRIEF_HOME');
    return $env ? rtrim($env, '/') : dirname(__DIR__, 2) . '/luxeaura-brief';
}

function brief_config(): array {
    $file = brief_home() . '/config.php';
    if (!is_file($file)) {
        json_out(['ok' => false, 'error' => 'not_configured'], 503);
    }
    $cfg = require $file;
    return is_array($cfg) ? $cfg : [];
}

function data_dir(string $sub = ''): string {
    $dir = brief_home() . '/data' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Simple brute-force brake on the access code: 10 failures per IP per 15 minutes.
function throttle_check(): void {
    $f = data_dir('throttle') . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 900));
    if (count($hits) >= 10) {
        json_out(['ok' => false, 'error' => 'too_many_attempts'], 429);
    }
}

function throttle_fail(): void {
    $f = data_dir('throttle') . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits[] = time();
    file_put_contents($f, json_encode($hits), LOCK_EX);
}

function load_answers(): array {
    $f = data_dir() . '/answers.json';
    if (!is_file($f)) {
        return ['fields' => new stdClass(), 'files' => new stdClass(), 'updatedAt' => null];
    }
    return json_decode((string) file_get_contents($f), true) ?: [];
}

function save_answers(array &$a): void {
    $a['updatedAt'] = gmdate('c');
    $json = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    file_put_contents(data_dir() . '/answers.json', $json, LOCK_EX);
    // Every save is also kept as a snapshot, so an accidental overwrite can be recovered.
    file_put_contents(data_dir('history') . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json', $json, LOCK_EX);
}

// Field keys: "logo_files" or repeater cells like "doctors:ab12cd34:photo".
function valid_field(string $k): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{1,40}(:[a-z0-9]{6,16}:[a-z][a-z0-9_]{1,30})?$/', $k);
}

function safe_name(string $name): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'file';
    return substr(trim($base, '-.'), 0, 60) . ($ext !== '' ? '.' . $ext : '');
}

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
