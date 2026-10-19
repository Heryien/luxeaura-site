<?php
// Admin view of the brief answers: read-only, password protected, files served
// through this script (they live outside the web root).
declare(strict_types=1);
require __DIR__ . '/lib.php';

session_name('luxebrief');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Strict']);
session_start();
$cfg = brief_config();

if (isset($_POST['password'])) {
    throttle_check();
    if (!empty($cfg['admin_password_hash']) && password_verify((string) $_POST['password'], (string) $cfg['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    throttle_fail();
    $loginError = true;
}
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}
$authed = !empty($_SESSION['admin']);

$answers = load_answers();
$fields = (array) ($answers['fields'] ?? []);
$files = (array) ($answers['files'] ?? []);

if ($authed && isset($_GET['dl'], $_GET['f'])) {
    $field = (string) $_GET['f'];
    $stored = basename((string) $_GET['dl']);
    if (!valid_field($field)) {
        http_response_code(400);
        exit;
    }
    $path = data_dir('uploads/' . str_replace(':', '/', $field)) . '/' . $stored;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . preg_replace('/^\d{14}-[0-9a-f]{6}-/', '', $stored) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
if ($authed && isset($_GET['export'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="luxeaura-brief-' . gmdate('Ymd-His') . '.json"');
    echo json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$schema = json_decode((string) file_get_contents(__DIR__ . '/questions.json'), true);

function render_value($v): string {
    if ($v === null || $v === '' || $v === []) {
        return '<span class="empty">Belum dijawab</span>';
    }
    if (is_bool($v)) {
        return $v ? 'Ya' : 'Tidak';
    }
    if (is_array($v)) {
        if (array_key_exists('choice', $v) || array_key_exists('other', $v) || array_key_exists('picked', $v)) {
            $parts = [];
            foreach ((array) ($v['picked'] ?? []) as $p) {
                $parts[] = h((string) $p);
            }
            if (!empty($v['choice'])) {
                $parts[] = h((string) $v['choice']);
            }
            if (!empty($v['other'])) {
                $parts[] = '<em>Lainnya:</em> ' . nl2br(h((string) $v['other']));
            }
            return $parts ? implode('<br>', $parts) : '<span class="empty">Belum dijawab</span>';
        }
        return '<pre>' . h(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
    }
    return nl2br(h((string) $v));
}

function render_files(array $files, string $field): string {
    $list = $files[$field] ?? [];
    if (!$list) {
        return '<span class="empty">Belum ada file</span>';
    }
    $out = '<ul class="files">';
    foreach ($list as $f) {
        $url = 'admin.php?f=' . rawurlencode($field) . '&dl=' . rawurlencode($f['stored']);
        $out .= '<li><a href="' . h($url) . '">' . h($f['name']) . '</a> <small>' . round(($f['size'] ?? 0) / 1024) . ' KB</small></li>';
    }
    return $out . '</ul>';
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin brief Luxe Aura</title>
<style>
  body { margin: 0; background: #f4eee4; color: #2f3324; font: 16px/1.6 system-ui, sans-serif; }
  main { max-width: 900px; margin: 0 auto; padding: 32px 16px 80px; }
  h1, h2 { font-family: Georgia, serif; font-weight: 500; }
  h2 { margin-top: 48px; border-bottom: 1px solid #a9884a; padding-bottom: 6px; }
  dl { margin: 0; }
  dt { font-weight: 600; margin-top: 20px; }
  dd { margin: 4px 0 0; }
  .empty { color: #6b6c5f; font-style: italic; }
  .row { border-left: 2px solid #a9884a; padding: 4px 0 4px 14px; margin: 10px 0; }
  pre { white-space: pre-wrap; background: #e6dccb; padding: 10px; }
  .bar { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
  a { color: #2f3324; } a:focus-visible, button:focus-visible, input:focus-visible { outline: 2px solid #a9884a; outline-offset: 2px; }
  input, button { font: inherit; padding: 10px 14px; min-height: 44px; }
  button { background: #2f3324; color: #f4eee4; border: 0; cursor: pointer; }
  .err { color: #8a1c1c; }
  ul.files { margin: 4px 0; padding-left: 18px; }
</style>
</head>
<body><main>
<?php if (!$authed): ?>
  <h1>Admin brief Luxe Aura</h1>
  <form method="post">
    <label for="pw">Password admin</label><br>
    <input id="pw" type="password" name="password" autocomplete="current-password" autofocus required>
    <button type="submit">Masuk</button>
    <?php if (!empty($loginError)): ?><p class="err">Password salah.</p><?php endif; ?>
  </form>
<?php else: ?>
  <div class="bar">
    <h1 style="margin-right:auto">Jawaban brief Luxe Aura</h1>
    <a href="admin.php?export=1">Unduh JSON</a>
    <a href="admin.php?logout=1">Keluar</a>
  </div>
  <p>Terakhir disimpan: <?= h($answers['updatedAt'] ?? 'belum ada') ?> (UTC)
    <?= !empty($fields['_status']) && $fields['_status'] === 'submitted' ? ' · <strong>Ditandai selesai oleh klien</strong>' : '' ?></p>
  <?php foreach ($schema['sections'] as $s): ?>
    <h2><?= h($s['id'] . '. ' . $s['title']) ?></h2>
    <dl>
    <?php foreach ($s['items'] as $it): $k = $it['key']; ?>
      <dt><?= h($it['label']) ?></dt>
      <dd>
      <?php if ($it['type'] === 'files'): ?>
        <?= render_files($files, $k) ?>
      <?php elseif ($it['type'] === 'repeater'): ?>
        <?php $rows = (array) ($fields[$k] ?? []); if (!$rows): ?><span class="empty">Belum dijawab</span><?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <div class="row">
          <?php foreach ($it['fields'] as $sub): $cell = $k . ':' . ($row['_id'] ?? '') . ':' . $sub['key']; ?>
            <div><strong><?= h($sub['label']) ?>:</strong>
              <?= $sub['type'] === 'files' ? render_files($files, $cell) : render_value($row[$sub['key']] ?? null) ?></div>
          <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <?= render_value($fields[$k] ?? null) ?>
      <?php endif; ?>
      </dd>
    <?php endforeach; ?>
    </dl>
  <?php endforeach; ?>
<?php endif; ?>
</main></body></html>
