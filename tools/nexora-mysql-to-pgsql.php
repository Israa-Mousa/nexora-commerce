<?php

/**
 * Nexora Commerce — copy store data from the local MySQL database into the
 * Render PostgreSQL database (whose tables were created by `migrate`).
 *
 * Safety rules:
 *  - MySQL is only READ.
 *  - PostgreSQL data is backed up locally before it is replaced.
 *  - A failed data replacement rolls back and preserves the existing Render data.
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

function quote_pg_identifier(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}

function get_pg_dependencies(PDO $pg): array
{
    $dependencies = [];

    foreach ($pg->query("
        SELECT tc.table_name AS child, ccu.table_name AS parent
        FROM information_schema.table_constraints tc
        JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
        WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
    ") as $row) {
        if ($row['child'] !== $row['parent']) {
            $dependencies[$row['child']][$row['parent']] = true;
        }
    }

    return $dependencies;
}

function order_pg_tables(array $tables, array $dependencies): array
{
    $ordered = [];
    $seen = [];
    $visit = function ($table) use (&$visit, &$ordered, &$seen, $dependencies, $tables) {
        if (isset($seen[$table])) {
            return;
        }

        $seen[$table] = true;

        foreach (array_keys($dependencies[$table] ?? []) as $parent) {
            if (in_array($parent, $tables, true)) {
                $visit($parent);
            }
        }

        $ordered[] = $table;
    };

    foreach ($tables as $table) {
        $visit($table);
    }

    return $ordered;
}

function lock_pg_tables(PDO $pg, array $tables): void
{
    if ($tables) {
        $quoted = array_map(
            fn ($table) => quote_pg_identifier('public').'.'.quote_pg_identifier($table),
            $tables
        );

        $pg->exec('LOCK TABLE '.implode(', ', $quoted).' IN ACCESS EXCLUSIVE MODE');
    }
}

function truncate_pg_tables(PDO $pg, array $tables): void
{
    $tables = array_values(array_filter($tables, fn ($table) => $table !== 'migrations'));

    if ($tables) {
        $quoted = array_map(fn ($table) => quote_pg_identifier($table), $tables);

        $pg->exec('TRUNCATE TABLE '.implode(', ', $quoted).' RESTART IDENTITY');
    }
}

function sync_pg_sequences(PDO $pg, array $tables): void
{
    foreach ($tables as $table) {
        $column = $pg->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'id'");
        $column->execute([$table]);

        if (! $column->fetchColumn()) {
            continue;
        }

        $tableName = $pg->quote('public.'.quote_pg_identifier($table));
        $sequence = $pg->query("SELECT pg_get_serial_sequence($tableName, 'id')")->fetchColumn();

        if (! $sequence) {
            continue;
        }

        $maximum = $pg->query('SELECT MAX(id) FROM '.quote_pg_identifier($table))->fetchColumn();

        if ($maximum === false || $maximum === null || (int) $maximum < 1) {
            $pg->query('SELECT setval('.$pg->quote($sequence).', 1, false)');
        } else {
            $pg->query('SELECT setval('.$pg->quote($sequence).', '.(int) $maximum.', true)');
        }
    }
}

function write_backup_line($file, string $line): void
{
    $offset = 0;
    $length = strlen($line);

    while ($offset < $length) {
        $written = fwrite($file, substr($line, $offset));

        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write all rows to the local Render backup.');
        }

        $offset += $written;
    }
}

function write_pg_backup(PDO $pg, array $tables, string $database, string $root): array
{
    $directory = $root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'nexora-backups';

    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create the local backup directory.');
    }

    $path = $directory.DIRECTORY_SEPARATOR.'render-before-import-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.jsonl';
    $file = fopen($path, 'xb');

    if (! $file) {
        throw new RuntimeException('Could not create the local Render backup file.');
    }

    $rows = 0;
    $hash = hash_init('sha256');

    try {
        $manifest = [
            'format' => 'nexora-render-data-v1',
            'database' => $database,
            'created_at' => date(DATE_ATOM),
            'tables' => $tables,
        ];
        $line = json_encode($manifest, JSON_THROW_ON_ERROR).PHP_EOL;
        write_backup_line($file, $line);

        foreach ($tables as $table) {
            $statement = $pg->query('SELECT * FROM '.quote_pg_identifier($table));

            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                foreach ($row as &$value) {
                    if ($value !== null) {
                        $value = (string) $value;
                    }
                }

                unset($value);

                $line = json_encode(['table' => $table, 'row' => $row], JSON_THROW_ON_ERROR).PHP_EOL;
                write_backup_line($file, $line);

                hash_update($hash, $line);
                $rows++;
            }
        }

        $line = json_encode([
            'footer' => 'nexora-render-data-v1',
            'rows' => $rows,
            'sha256' => hash_final($hash),
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        write_backup_line($file, $line);

        if (! fflush($file)) {
            throw new RuntimeException('Could not flush the local Render backup to disk.');
        }
    } catch (Throwable $e) {
        fclose($file);
        @unlink($path);

        throw $e;
    }

    fclose($file);

    if (! filesize($path)) {
        @unlink($path);

        throw new RuntimeException('The local Render backup file is empty.');
    }

    return [$path, $rows];
}

function restore_pg_backup(PDO $pg, string $path, string $database, array $pgTables, array $dependencies): void
{
    $file = fopen($path, 'rb');

    if (! $file) {
        throw new RuntimeException('The backup file could not be opened.');
    }

    $manifestLine = fgets($file);
    $manifest = $manifestLine === false ? null : json_decode($manifestLine, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($manifest) || ($manifest['format'] ?? null) !== 'nexora-render-data-v1' || ! is_array($manifest['tables'] ?? null)) {
        fclose($file);

        throw new RuntimeException('The selected file is not a Nexora Render backup.');
    }

    $tables = array_values(array_filter($manifest['tables'], fn ($table) => $table !== 'migrations'));
    $currentTables = array_values(array_filter($pgTables, fn ($table) => $table !== 'migrations'));
    $knownTables = array_flip($pgTables);

    if (($manifest['database'] ?? null) !== $database || array_diff($currentTables, $tables) || array_diff($tables, $currentTables)) {
        fclose($file);

        throw new RuntimeException('The backup database or schema does not match the current Render database.');
    }

    foreach ($tables as $table) {
        if (! isset($knownTables[$table])) {
            fclose($file);

            throw new RuntimeException("The backup table '$table' is not present in the current Render schema.");
        }
    }

    $rowsByTable = array_fill_keys($tables, []);
    $rows = 0;
    $hash = hash_init('sha256');
    $footerSeen = false;

    while (($line = fgets($file)) !== false) {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($record)) {
            fclose($file);

            throw new RuntimeException('The Render backup contains an invalid row record.');
        }

        if (($record['footer'] ?? null) === 'nexora-render-data-v1') {
            $digest = hash_final($hash);

            if (($record['rows'] ?? null) !== $rows || ! is_string($record['sha256'] ?? null) || ! hash_equals($digest, $record['sha256'])) {
                fclose($file);

                throw new RuntimeException('The local Render backup failed its integrity check.');
            }

            $footerSeen = true;
            break;
        }

        $table = $record['table'] ?? null;

        if (! is_string($table) || ! array_key_exists($table, $rowsByTable) || ! is_array($record['row'] ?? null)) {
            fclose($file);

            throw new RuntimeException('The Render backup contains an invalid row record.');
        }

        foreach ($record['row'] as $value) {
            if ($value !== null && ! is_string($value)) {
                fclose($file);

                throw new RuntimeException('The Render backup row contains an unsupported value.');
            }
        }

        $rowsByTable[$table][] = $record['row'];
        hash_update($hash, $line);
        $rows++;
    }

    if (! $footerSeen || fgets($file) !== false) {
        fclose($file);

        throw new RuntimeException('The local Render backup is incomplete or has trailing data.');
    }

    fclose($file);

    $pg->beginTransaction();

    try {
        lock_pg_tables($pg, $pgTables);
        truncate_pg_tables($pg, $pgTables);

        foreach (order_pg_tables($tables, $dependencies) as $table) {
            $rows = $rowsByTable[$table];

            if (! $rows) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $sql = 'INSERT INTO '.quote_pg_identifier($table)
                .' ('.implode(',', array_map('quote_pg_identifier', $columns)).') VALUES ('
                .implode(',', array_fill(0, count($columns), '?')).')';
            $statement = $pg->prepare($sql);

            foreach ($rows as $row) {
                if (array_keys($row) !== $columns) {
                    throw new RuntimeException("The backup rows for '$table' have inconsistent columns.");
                }

                $statement->execute(array_values($row));
            }
        }

        $pg->commit();
        sync_pg_sequences($pg, $tables);
    } catch (Throwable $e) {
        if ($pg->inTransaction()) {
            $pg->rollBack();
        }

        throw $e;
    }
}

$restorePath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--restore=')) {
        $restorePath = substr($argument, strlen('--restore='));
    }
}

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

$pgTables = $pg->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

if (! $pgTables) {
    fwrite(STDERR, "PostgreSQL has no tables yet. Deploy once on Render (it runs the migrations), then run this again.\n");
    exit(1);
}

$dependencies = get_pg_dependencies($pg);

if ($restorePath) {
    try {
        restore_pg_backup($pg, $restorePath, ltrim($u['path'], '/'), $pgTables, $dependencies);
        echo "Render data restored from the local backup.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Backup restore or sequence synchronization failed. {$e->getMessage()}\n");
        exit(1);
    }
}

$env = env_file($root.'/.env');

$mysql = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE']),
    $env['DB_USERNAME'] ?? 'root',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
);

echo "Connected to MySQL ({$env['DB_DATABASE']}) and PostgreSQL.\n";

$mysqlTables = $mysql->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
$mysqlTables = array_map(fn ($r) => $r[0], $mysqlTables);

$skipAlways = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

$todo = array_values(array_filter($pgTables, fn ($t) => in_array($t, $mysqlTables, true) && ! in_array($t, $skipAlways, true)));
$ordered = order_pg_tables($todo, $dependencies);
$backupTables = array_values(array_filter($pgTables, fn ($table) => $table !== 'migrations'));
$backupTables = order_pg_tables($backupTables, $dependencies);

$report = [];
$pending = $ordered;
$backupPath = null;
$pg->beginTransaction();

try {
    lock_pg_tables($pg, $pgTables);
    [$backupPath, $backupRows] = write_pg_backup($pg, $backupTables, ltrim($u['path'], '/'), $root);
    echo "Local backup saved: $backupPath ($backupRows rows).\n";
    truncate_pg_tables($pg, $pgTables);

    for ($pass = 1; $pass <= 4 && $pending; $pass++) {
        $retry = [];

        foreach ($pending as $table) {
            $target = (int) $pg->query('SELECT COUNT(*) FROM '.quote_pg_identifier($table))->fetchColumn();
            $source = (int) $mysql->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();

            if ($target > 0) {
                $report[$table] = ['source' => $source, 'target' => $target, 'status' => 'FAILED: target table was not cleared'];

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

            $colList = implode(',', array_map('quote_pg_identifier', $common));
            $batch = [];
            $copied = 0;

            $flush = function () use (&$batch, &$copied, $pg, $table, $colList, $common) {
                if (! $batch) {
                    return;
                }

                $row = '('.implode(',', array_fill(0, count($common), '?')).')';
                $sql = 'INSERT INTO '.quote_pg_identifier($table).' ('.$colList.') VALUES '.implode(',', array_fill(0, count($batch), $row));
                $params = [];
                foreach ($batch as $values) {
                    foreach ($values as $value) {
                        $params[] = $value;
                    }
                }

                $pg->prepare($sql)->execute($params);
                $copied += count($batch);
                $batch = [];
            };

            $pg->exec('SAVEPOINT import_table');

            try {
                while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($common as $column) {
                        $value = $row[$column];
                        $type = $pgCols[$column];
                        if ($value !== null && $type === 'boolean') {
                            $value = ((string) $value === '1' || $value === true) ? 'true' : 'false';
                        } elseif (is_string($value) && str_starts_with($value, '0000-00-00')) {
                            $value = null;
                        }
                        $values[] = $value;
                    }
                    $batch[] = $values;
                    if (count($batch) >= 300) {
                        $flush();
                    }
                }

                $flush();
                $pg->exec('RELEASE SAVEPOINT import_table');
            } catch (Throwable $e) {
                $pg->exec('ROLLBACK TO SAVEPOINT import_table');
                $pg->exec('RELEASE SAVEPOINT import_table');
                $select->closeCursor();
                if ($pass < 4 && str_contains($e->getMessage(), 'foreign key')) {
                    $retry[] = $table;

                    continue;
                }
                $report[$table] = ['source' => $source, 'target' => 0, 'status' => 'FAILED: '.substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160)];

                continue;
            }

            $report[$table] = ['source' => $source, 'target' => $copied, 'status' => $copied === $source ? 'OK' : 'DIFF'];
            echo str_pad($table, 42).str_pad((string) $copied, 8, ' ', STR_PAD_LEFT)." rows\n";
        }

        $pending = $retry;
    }

    foreach ($pending as $table) {
        $report[$table] = ['source' => '?', 'target' => 0, 'status' => 'FAILED: foreign key order'];
    }

    $problems = array_filter($report, fn ($row) => ! in_array($row['status'], ['OK', 'empty'], true));
    echo "\nTables copied: ".count(array_filter($report, fn ($row) => $row['status'] === 'OK'))."\n";

    if ($problems) {
        echo "Needs attention:\n";
        foreach ($problems as $table => $row) {
            echo '  '.str_pad($table, 40)." {$row['source']} -> {$row['target']}  {$row['status']}\n";
        }

        $pg->rollBack();
        fwrite(STDERR, "The Render database was restored to its pre-import state. Local backup: $backupPath\n");
        exit(1);
    }

    $pg->commit();
    try {
        sync_pg_sequences($pg, $ordered);
    } catch (Throwable $e) {
        fwrite(STDERR, "Data replacement committed, but sequence synchronization failed. Backup: $backupPath. {$e->getMessage()}\n");
        exit(1);
    }
} catch (Throwable $e) {
    if ($pg->inTransaction()) {
        $pg->rollBack();
    }

    fwrite(STDERR, "Import failed; no replacement was committed. {$e->getMessage()}\n");
    if ($backupPath) {
        fwrite(STDERR, "Local backup: $backupPath\n");
    }
    exit(1);
}

echo "All tables copied.\n";
echo "Keep the local backup until the storefront has been verified.\n";
echo 'Restore it with -RestoreBackup "'.$backupPath."\"\n";
