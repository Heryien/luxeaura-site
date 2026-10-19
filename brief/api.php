<?php
// Client API for the brief form. Every action requires the access code.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method'], 405);
}

$cfg = brief_config();
throttle_check();
$code = (string) ($_POST['code'] ?? '');
if ($code === '' || empty($cfg['client_code']) || !hash_equals((string) $cfg['client_code'], $code)) {
    throttle_fail();
    json_out(['ok' => false, 'error' => 'bad_code'], 401);
}

$action = (string) ($_POST['action'] ?? '');
$answers = load_answers();
$answers['fields'] = (array) ($answers['fields'] ?? []);
$answers['files'] = (array) ($answers['files'] ?? []);

switch ($action) {
    case 'load':
        json_out(['ok' => true, 'answers' => $answers]);

    case 'save':
        $fields = json_decode((string) ($_POST['fields'] ?? ''), true);
        if (!is_array($fields)) {
            json_out(['ok' => false, 'error' => 'bad_payload'], 400);
        }
        $clean = [];
        foreach ($fields as $k => $v) {
            if (!is_string($k) || !preg_match('/^[a-z_][a-z0-9_]{1,40}$/', $k)) {
                continue;
            }
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($enc === false || strlen($enc) > 100000) {
                continue;
            }
            $clean[$k] = $v;
        }
        $answers['fields'] = $clean;
        save_answers($answers);
        json_out(['ok' => true, 'updatedAt' => $answers['updatedAt']]);

    case 'upload':
        $field = (string) ($_POST['field'] ?? '');
        if (!valid_field($field) || empty($_FILES['file'])) {
            json_out(['ok' => false, 'error' => 'bad_field'], 400);
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $err = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'upload_failed';
            json_out(['ok' => false, 'error' => $err], 400);
        }
        if ($f['size'] > MAX_FILE_BYTES) {
            json_out(['ok' => false, 'error' => 'too_large'], 400);
        }
        $name = safe_name((string) $f['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXT, true)) {
            json_out(['ok' => false, 'error' => 'bad_type'], 400);
        }
        $dir = data_dir('uploads/' . str_replace(':', '/', $field));
        $stored = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '-' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            json_out(['ok' => false, 'error' => 'upload_failed'], 500);
        }
        $answers['files'][$field][] = ['name' => $name, 'stored' => $stored, 'size' => (int) $f['size'], 'at' => gmdate('c')];
        save_answers($answers);
        json_out(['ok' => true, 'files' => $answers['files'][$field]]);

    case 'delete_file':
        $field = (string) ($_POST['field'] ?? '');
        $stored = basename((string) ($_POST['stored'] ?? ''));
        if (!valid_field($field) || $stored === '') {
            json_out(['ok' => false, 'error' => 'bad_field'], 400);
        }
        $list = $answers['files'][$field] ?? [];
        $answers['files'][$field] = array_values(array_filter($list, fn($x) => $x['stored'] !== $stored));
        // Keep the bytes on disk (admin can still recover them); only the reference is removed.
        save_answers($answers);
        json_out(['ok' => true, 'files' => $answers['files'][$field]]);

    default:
        json_out(['ok' => false, 'error' => 'bad_action'], 400);
}
