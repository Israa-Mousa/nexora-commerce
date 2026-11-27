<?php
/**
 * Nexora Commerce — copy store data from the local MySQL database into the
 * Render PostgreSQL database (whose tables were created by `migrate`).
 *
 * Safety rules:
 *  - MySQL is only READ.
 *  - Only EMPTY PostgreSQL tables are filled; tables that already hold rows are
 *    reported and skipped. Nothing is dropped, truncated or deleted.
 *  - The PostgreSQL URL comes from the NEXORA_PG_URL environment variable and is
 *    never printed.
 *
 * Run through nexora-db-to-render.ps1 (it sets the variable and the PHP flags).
 */

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

$root = dirname(__DIR__);

function env_file(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $out[$m[1]] = $v;
        }
    }
    return $out;
}

$env = env_file($root.'/.env');

$mysql = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE']),
    $env['DB_USERNAME'] ?? 'root',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
);

$url = getenv('NEXORA_PG_URL');
if (! $url) {
    fwrite(STDERR, "NEXORA_PG_URL is not set.\n");
    exit(1);
}
$u = parse_url($url);
$pg = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=require', $u['host'], $u['port'] ?? 5432, ltrim($u['path'], '/')),
    urldecode($u['user']),
    urldecode($u['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Connected to MySQL ({$env['DB_DATABASE']}) and PostgreSQL.\n";

$mysqlTables = $mysql->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
$mysqlTables = array_map(fn ($r) => $r[0], $mysqlTables);

$pgTables = $pg->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

if (! $pgTables) {
    fwrite(STDERR, "PostgreSQL has no tables yet. Deploy once on Render (it runs the migrations), then run this again.\n");
    exit(1);
}

$skipAlways = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

// Foreign-key dependencies, so parents are copied before children.
$deps = [];
foreach ($pg->query("
    SELECT tc.table_name AS child, ccu.table_name AS parent
    FROM information_schema.table_constraints tc
    JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
") as $r) {
    if ($r['child'] !== $r['parent']) {
        $deps[$r['child']][$r['parent']] = true;
    }
}

$todo = array_values(array_filter($pgTables, fn ($t) => in_array($t, $mysqlTables, true) && ! in_array($t, $skipAlways, true)));
$ordered = [];
$seen = [];
$visit = function ($t) use (&$visit, &$ordered, &$seen, $deps, $todo) {
    if (isset($seen[$t])) {
        return;
    }
    $seen[$t] = true;
    foreach (array_keys($deps[$t] ?? []) as $p) {
        if (in_array($p, $todo, true)) {
            $visit($p);
        }
    }
    $ordered[] = $t;
};
foreach ($todo as $t) {
    $visit($t);
}

$report = [];
$pending = $ordered;

for ($pass = 1; $pass <= 4 && $pending; $pass++) {
    $retry = [];

    foreach ($pending as $table) {
        $target = (int) $pg->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
        $source = (int) $mysql->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();

        if ($target > 0) {
            $report[$table] = ['source' => $source, 'target' => $target, 'status' => 'skipped (already has rows)'];
            continue;
        }
        if ($source === 0) {
            $report[$table] = ['source' => 0, 'target' => 0, 'status' => 'empty'];
            continue;
        }

        $cols = $pg->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?");
        $cols->execute([$table]);
        $pgCols = $cols->fetchAll(PDO::FETCH_KEY_PAIR);

        $myCols = array_map(fn ($r) => $r['Field'], $mysql->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
        $common = array_values(array_filter($myCols, fn ($c) => isset($pgCols[$c])));

        $orderBy = in_array('id', $common, true) ? ' ORDER BY `id`' : '';
        $select = $mysql->query('SELECT `'.implode('`,`', $common).'` FROM `'.$table.'`'.$orderBy);

        $colList = '"'.implode('","', $common).'"';
        $batch = [];
        $copied = 0;

        $flush = function () use (&$batch, &$copied, $pg, $table, $colList, $common) {
            if (! $batch) {
                return;
            }
            $row = '('.implode(',', array_fill(0, count($common), '?')).')';
            $sql = 'INSERT INTO "'.$table.'" ('.$colList.') VALUES '.implode(',', array_fill(0, count($batch), $row));
            $params = [];
            foreach ($batch as $r) {
                foreach ($r as $v) {
                    $params[] = $v;
                }
            }
            $pg->prepare($sql)->execute($params);
            $copied += count($batch);
            $batch = [];
        };

        $pg->beginTransaction();
        try {
            while ($r = $select->fetch(PDO::FETCH_ASSOC)) {
                $vals = [];
                foreach ($common as $c) {
                    $v = $r[$c];
                    $type = $pgCols[$c];
                    if ($v !== null && $type === 'boolean') {
                        $v = ((string) $v === '1' || $v === true) ? 'true' : 'false';
                    } elseif (is_string($v) && str_starts_with($v, '0000-00-00')) {
                        $v = null;
                    }
                    $vals[] = $v;
                }
                $batch[] = $vals;
                if (count($batch) >= 300) {
                    $flush();
                }
            }
            $flush();
            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            $select->closeCursor();
            if ($pass < 4 && str_contains($e->getMessage(), 'foreign key')) {
                $retry[] = $table;
                continue;
            }
            $report[$table] = ['source' => $source, 'target' => 0, 'status' => 'FAILED: '.substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160)];
            continue;
        }

        if (in_array('id', $common, true) && in_array($pgCols['id'] ?? '', ['integer', 'bigint'], true)) {
            $seq = $pg->query("SELECT pg_get_serial_sequence('\"{$table}\"', 'id')")->fetchColumn();
            if ($seq) {
                $pg->query("SELECT setval('{$seq}', COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1))");
            }
        }

        $report[$table] = ['source' => $source, 'target' => $copied, 'status' => $copied === $source ? 'OK' : 'DIFF'];
        echo str_pad($table, 42).str_pad((string) $copied, 8, ' ', STR_PAD_LEFT)." rows\n";
    }

    $pending = $retry;
}

foreach ($pending as $t) {
    $report[$t] = ['source' => '?', 'target' => 0, 'status' => 'FAILED: foreign key order'];
}

$problems = array_filter($report, fn ($r) => ! in_array($r['status'], ['OK', 'empty'], true));
echo "\nTables copied: ".count(array_filter($report, fn ($r) => $r['status'] === 'OK'))."\n";
if ($problems) {
    echo "Needs attention:\n";
    foreach ($problems as $t => $r) {
        echo '  '.str_pad($t, 40)." {$r['source']} -> {$r['target']}  {$r['status']}\n";
    }
    exit(1);
}

echo "All tables copied.\n";
