<?php
declare(strict_types=1);

const APP_NAME = 'Daily Task Manager';
const DB_FILE = __DIR__ . '/../data/task_manager.sqlite';

date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('daily_task_manager');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PHP SQLite support is not enabled. Enable extension=pdo_sqlite in php.ini and restart PHP.');
    }

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    initDatabase($pdo);
    return $pdo;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) return true;
    }
    return false;
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!hasColumn($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function initDatabase(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'employee' CHECK(role IN ('admin','employee')),
    department TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive'))
);
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    employee_id INTEGER NOT NULL,
    task_date TEXT NOT NULL,
    task_description TEXT NOT NULL,
    category_id INTEGER,
    priority TEXT NOT NULL DEFAULT 'Medium' CHECK(priority IN ('Low','Medium','High','Critical')),
    due_date TEXT,
    status TEXT NOT NULL DEFAULT 'Not Started' CHECK(status IN ('Not Started','In Progress','Completed','Pending','Blocked','Cancelled')),
    remarks TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(employee_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS google_sheet_settings (
    id INTEGER PRIMARY KEY CHECK(id = 1),
    sheet_url TEXT NOT NULL DEFAULT '',
    sheet_id TEXT NOT NULL DEFAULT '',
    gid TEXT NOT NULL DEFAULT '0',
    sync_interval INTEGER NOT NULL DEFAULT 60,
    sync_year INTEGER NOT NULL DEFAULT 2026,
    enabled INTEGER NOT NULL DEFAULT 0,
    last_sync_at TEXT,
    last_sync_status TEXT,
    last_sync_message TEXT,
    last_sync_count INTEGER NOT NULL DEFAULT 0
);
SQL);

    // Google Sheet columns. These migrations also upgrade older ZIP/database copies safely.
    $taskColumns = [
        'client_name' => 'TEXT',
        'task_type' => 'TEXT',
        'poc' => 'TEXT',
        'content_responsible' => 'TEXT',
        'responsible_editor' => 'TEXT',
        'reference_links' => 'TEXT',
        'time_taken' => 'TEXT',
        'editor_remarks' => 'TEXT',
        'acc_manager_remark' => 'TEXT',
        'manager_remark' => 'TEXT',
        'sheet_day' => 'TEXT',
        'raw_status' => 'TEXT',
        'raw_priority' => 'TEXT',
        'source' => "TEXT NOT NULL DEFAULT 'manual'",
        'source_sheet_key' => 'TEXT',
        'source_row' => 'INTEGER',
        'synced_at' => 'TEXT',
    ];
    foreach ($taskColumns as $name => $definition) addColumnIfMissing($pdo, 'tasks', $name, $definition);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_employee ON tasks(employee_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_date ON tasks(task_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_category ON tasks(category_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_source ON tasks(source)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_sheet_key ON tasks(source_sheet_key)');

    $pdo->prepare('INSERT OR IGNORE INTO google_sheet_settings(id,sync_year) VALUES(1,?)')->execute([(int)date('Y')]);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,department,status) VALUES(?,?,?,?,?,?)');
        $stmt->execute(['System Admin','admin@example.com', password_hash('Admin123!', PASSWORD_DEFAULT), 'admin','Management','active']);
    }

    $catCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($catCount === 0) {
        $stmt = $pdo->prepare('INSERT INTO categories(name,status) VALUES(?,?)');
        foreach (['Daily Work','Client Work','Internal','Follow-up','Meeting','Learning'] as $name) $stmt->execute([$name,'active']);
    }
}
