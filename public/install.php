<?php
/**
 * public/install.php
 * DreamAI installer — runs DB migrations and seed safely with a token gate.
 *
 * Usage:
 *   http://localhost/dreamai/public/install.php?token=YOUR_TOKEN
 *
 * Requirements:
 *   - bootstrap.php (loads .env, config, classes)
 *   - migrations/001_schema.sql
 *   - seed/lexicon_seed.sql
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;

// -----------------------------------------------------------------------------
// Security: token gate
// -----------------------------------------------------------------------------
$given = $_GET['token'] ?? '';
$need  = $config['app']['install_token'] ?? '';
if ($need === '' || $given !== $need) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title>';
    echo '<h1>403 Forbidden</h1><p>Missing or incorrect install token.</p>';
    exit;
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
/**
 * Read an SQL file and split into executable statements.
 * Handles:
 *  - line comments:  -- ...  or  # ...
 *  - block comments: /* ... * /      (spacing added here to avoid closing)
 *  - quoted strings: 'single', "double", `identifier`
 *  - avoids splitting on semicolons inside quotes/comments
 * Returns an array of SQL statements (without trailing semicolons).
 */
function sqlFileToStatements(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("SQL file not found: {$path}");
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Unable to read SQL file: {$path}");
    }

    $len = strlen($sql);
    $stmts = [];
    $buf = '';

    $inSingle = false;   // '
    $inDouble = false;   // "
    $inBack   = false;   // `
    $inLine   = false;   // -- or #
    $inBlock  = false;   // /* ... */

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $nx = $i + 1 < $len ? $sql[$i + 1] : '';

        // Handle end of line comment
        if ($inLine) {
            if ($ch === "\n") {
                $inLine = false;
                // keep newline to preserve formatting between statements
                $buf .= $ch;
            }
            continue;
        }

        // Handle end of block comment
        if ($inBlock) {
            if ($ch === '*' && $nx === '/') {
                $inBlock = false;
                $i++; // consume '/'
            }
            continue;
        }

        // Start of comments (when not inside quotes)
        if (!$inSingle && !$inDouble && !$inBack) {
            // -- style (must be start of line or preceded by whitespace)
            if ($ch === '-' && $nx === '-') {
                // ensure previous char is start or whitespace
                $prev = $i > 0 ? $sql[$i - 1] : "\n";
                if (ctype_space($prev) || $prev === "\n" || $prev === "\r") {
                    $inLine = true;
                    $i++; // consume second '-'
                    continue;
                }
            }
            // # style
            if ($ch === '#') {
                $inLine = true;
                continue;
            }
            // /* ... */ style
            if ($ch === '/' && $nx === '*') {
                $inBlock = true;
                $i++; // consume '*'
                continue;
            }
        }

        // Quoted strings toggles
        if ($ch === "'" && !$inDouble && !$inBack) {
            // If previous char is backslash inside single-quoted string, it's escaped
            $inSingle = !$inSingle;
            $buf .= $ch;
            continue;
        }
        if ($ch === '"' && !$inSingle && !$inBack) {
            $inDouble = !$inDouble;
            $buf .= $ch;
            continue;
        }
        if ($ch === '`' && !$inSingle && !$inDouble) {
            $inBack = !$inBack;
            $buf .= $ch;
            continue;
        }

        // Statement boundary
        if ($ch === ';' && !$inSingle && !$inDouble && !$inBack) {
            $trimmed = trim($buf);
            if ($trimmed !== '') {
                $stmts[] = $trimmed;
            }
            $buf = '';
            continue;
        }

        // Accumulate
        $buf .= $ch;
    }

    // Final buffer
    $trimmed = trim($buf);
    if ($trimmed !== '') {
        $stmts[] = $trimmed;
    }

    // Filter out empty artifacts
    $stmts = array_values(array_filter($stmts, static fn($s) => trim($s) !== ''));

    return $stmts;
}

/**
 * Execute all statements and collect results.
 * Returns array of ['ok' => bool, 'sql' => string, 'error' => string|null]
 */
function execStatements(PDO $pdo, array $stmts): array
{
    $out = [];
    foreach ($stmts as $s) {
        try {
            $pdo->exec($s);
            $out[] = ['ok' => true, 'sql' => $s, 'error' => null];
        } catch (Throwable $e) {
            $out[] = ['ok' => false, 'sql' => $s, 'error' => $e->getMessage()];
        }
    }
    return $out;
}

// -----------------------------------------------------------------------------
// Run installer
// -----------------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');

$db = new DB($config['db']);
$pdo = $db->pdo;

$migrationFile = realpath(__DIR__ . '/../migrations/001_schema.sql');
$seedFile      = realpath(__DIR__ . '/../seed/lexicon_seed.sql');

$results = [
    'migrations' => [],
    'seed'       => [],
];
$errors = [];

try {
    // Migrations
    $mig = sqlFileToStatements($migrationFile);
    $results['migrations'] = execStatements($pdo, $mig);

    // Seed
    $sed = sqlFileToStatements($seedFile);
    $results['seed'] = execStatements($pdo, $sed);

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

// -----------------------------------------------------------------------------
// Render minimal HTML report
// -----------------------------------------------------------------------------
function renderTable(array $rows): string
{
    $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
    $html .= '<tr style="background:#f3f4f6"><th style="width:80px">Status</th><th>Statement</th><th style="width:35%">Error</th></tr>';
    foreach ($rows as $r) {
        $ok = $r['ok'];
        $sql = htmlspecialchars($r['sql'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $err = $r['error'] ? htmlspecialchars($r['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
        $badge = $ok ? '<span style="color:#065f46">OK</span>' : '<span style="color:#991b1b">FAIL</span>';
        $html .= "<tr><td>{$badge}</td><td><pre style=\"white-space:pre-wrap;margin:0\">{$sql}</pre></td><td>{$err}</td></tr>";
    }
    $html .= '</table>';
    return $html;
}

$okMigrations = array_reduce($results['migrations'], fn($c,$r)=>$c && $r['ok'], true);
$okSeed       = array_reduce($results['seed'], fn($c,$r)=>$c && $r['ok'], true);

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>DreamAI Installer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,'Noto Sans',sans-serif;margin:24px;color:#111827}
    .container{max-width:1100px;margin:0 auto}
    .card{border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:16px 0}
    .ok{color:#065f46;background:#ecfdf5;border:1px solid #a7f3d0;padding:6px 10px;border-radius:10px;display:inline-block}
    .fail{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;padding:6px 10px;border-radius:10px;display:inline-block}
    a.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #d1d5db;text-decoration:none;color:#111827}
    a.btn.primary{background:#111827;color:#fff;border-color:#111827}
    pre{white-space:pre-wrap}
  </style>
</head>
<body>
<div class="container">
  <h1>🔧 DreamAI Installer</h1>

  <div class="card">
    <p><strong>Environment:</strong> <?= htmlspecialchars($config['app']['env'] ?? 'local') ?></p>
    <p><strong>Database:</strong> <?= htmlspecialchars(($config['db']['host'] ?? '').':'.($config['db']['port'] ?? '').' / '.($config['db']['name'] ?? '')) ?></p>
  </div>

  <?php if ($errors): ?>
    <div class="card fail">
      <h3>เกิดข้อผิดพลาด</h3>
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>1) Migrations <?= $okMigrations ? '<span class="ok">สำเร็จ</span>' : '<span class="fail">บางส่วนล้มเหลว</span>' ?></h2>
    <?= renderTable($results['migrations']); ?>
  </div>

  <div class="card">
    <h2>2) Seed <?= $okSeed ? '<span class="ok">สำเร็จ</span>' : '<span class="fail">บางส่วนล้มเหลว</span>' ?></h2>
    <?= renderTable($results['seed']); ?>
  </div>

  <div class="card">
    <?php if ($okMigrations && $okSeed && !$errors): ?>
      <h3>🎉 ติดตั้งเสร็จสมบูรณ์</h3>
      <p>คุณสามารถเริ่มใช้งานแอปได้แล้ว</p>
      <p>
        <a class="btn primary" href="index.php">ไปหน้าแรก</a>
        <a class="btn" href="?token=<?= urlencode($need) ?>">รันซ้ำ</a>
      </p>
      <p style="color:#6b7280;font-size:12px">ข้อแนะนำด้านความปลอดภัย: ลบไฟล์ <code>public/install.php</code> หรือเปลี่ยนค่า <code>INSTALL_TOKEN</code> ทันทีหลังติดตั้ง</p>
    <?php else: ?>
      <h3>⚠️ กรุณาตรวจสอบข้อผิดพลาดด้านบน</h3>
      <p>แก้ไขแล้วกด “รันซ้ำ”</p>
      <p><a class="btn" href="?token=<?= urlencode($need) ?>">รันซ้ำ</a></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
