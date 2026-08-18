<?php
declare(strict_types=1);

/**
 * Google Sheet connector for a public/viewer-accessible sheet.
 * No Google API key is required. The application reads the selected sheet tab as CSV.
 */

function sheetLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function normalizeSheetHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
    $value = sheetLower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function parseGoogleSheetUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') throw new InvalidArgumentException('Paste your Google Sheet link first.');

    if (!preg_match('~spreadsheets/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
        throw new InvalidArgumentException('This does not look like a Google Sheet link. Open the sheet, copy the browser URL, and paste it here.');
    }
    $sheetId = $m[1];
    $gid = '0';
    if (preg_match('/(?:[?&#]|%3F|%26)gid(?:=|%3D)(\d+)/i', $url, $g)) $gid = $g[1];

    return [
        'sheet_id' => $sheetId,
        'gid' => $gid,
        'sheet_key' => $sheetId . ':' . $gid,
        'csv_url' => "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:csv&gid={$gid}",
    ];
}

function httpGetText(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'DailyTaskManager/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/csv,text/plain,*/*'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Could not connect to Google Sheets: ' . ($error ?: 'unknown connection error'));
        if ($status >= 400) throw new RuntimeException("Google Sheets returned HTTP {$status}. Check that the sheet is shared as Anyone with the link - Viewer.");
        return (string)$body;
    }

    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new RuntimeException('PHP cURL is not enabled. In XAMPP php.ini, enable extension=curl and restart PHP.');
    }
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: DailyTaskManager/1.0\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new RuntimeException('Could not read Google Sheet. Check the sheet sharing permission and internet connection.');
    return $body;
}

function csvRows(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    if (!$stream) throw new RuntimeException('Could not prepare Sheet data.');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        $rows[] = array_map(static fn($v) => trim((string)$v), $row);
    }
    fclose($stream);
    return $rows;
}

function findSheetHeader(array $rows): array
{
    foreach (array_slice($rows, 0, 20, true) as $rowIndex => $row) {
        $map = [];
        foreach ($row as $i => $cell) {
            $key = normalizeSheetHeader((string)$cell);
            if ($key !== '') $map[$key] = $i;
        }
        if (isset($map['tasks']) && (isset($map['date']) || isset($map['date']) || isset($map['responsibleeditor']))) {
            return [(int)$rowIndex, $map];
        }
    }
    throw new RuntimeException('Could not find the header row. Keep column names like Date, Name, Type, Tasks and Responsible editor in the sheet.');
}

function sheetCell(array $row, array $map, array $keys): string
{
    foreach ($keys as $key) {
        $k = normalizeSheetHeader($key);
        if (array_key_exists($k, $map)) {
            $idx = (int)$map[$k];
            return trim((string)($row[$idx] ?? ''));
        }
    }
    return '';
}

function parseSheetDate(string $value, int $defaultYear): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    $value = str_replace([',', '.'], [' ', ' '], $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    $formats = ['Y-m-d','d-m-Y','d/m/Y','d M Y','d F Y','j M Y','j F Y','m/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($dt && $dt->format($format) === $value) return $dt->format('Y-m-d');
    }

    // Screenshot-style dates such as "19 August" or "18 Aug".
    foreach (['j F Y','j M Y'] as $format) {
        $candidate = $value . ' ' . $defaultYear;
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $candidate);
        if ($dt) return $dt->format('Y-m-d');
    }

    try {
        $dt = new DateTimeImmutable($value);
        // If Google returned a date without a year, PHP may choose the current year; force configured year.
        if (!preg_match('/\b\d{4}\b/', $value)) $dt = $dt->setDate($defaultYear, (int)$dt->format('m'), (int)$dt->format('d'));
        return $dt->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

function normalizeSheetStatus(string $raw): string
{
    $v = sheetLower(trim($raw));
    $v = preg_replace('/[^a-z]+/', ' ', $v) ?? $v;
    $v = trim($v);
    if ($v === '') return 'Not Started';
    if (str_contains($v, 'done') || str_contains($v, 'complete')) return 'Completed';
    if ($v === 'wip' || str_contains($v, 'in progress') || str_contains($v, 'working')) return 'In Progress';
    if (str_contains($v, 'pending')) return 'Pending';
    if (str_contains($v, 'block') || str_contains($v, 'hold')) return 'Blocked';
    if (str_contains($v, 'cancel')) return 'Cancelled';
    if (str_contains($v, 'not started')) return 'Not Started';
    return 'Not Started';
}

function normalizeSheetPriority(string $raw): string
{
    $v = sheetLower(trim($raw));
    if ($v === '') return 'Medium';
    if (str_contains($v, 'urgent') || str_contains($v, 'critical')) return 'Critical';
    if (str_contains($v, 'high') || $v === 'imp' || str_contains($v, 'important')) return 'High';
    if (str_contains($v, 'low')) return 'Low';
    return 'Medium';
}

function sheetUserId(PDO $pdo, string $name): int
{
    $name = trim($name) ?: 'Unassigned';
    $st = $pdo->prepare('SELECT id FROM users WHERE lower(name)=lower(?) LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;

    $slug = sheetLower($name);
    $slug = preg_replace('/[^a-z0-9]+/u', '.', $slug) ?? 'employee';
    $slug = trim($slug, '.') ?: 'employee';
    $base = "sheet.{$slug}@local.invalid";
    $email = $base;
    $n = 2;
    while (true) {
        $q = $pdo->prepare('SELECT 1 FROM users WHERE email=?');
        $q->execute([$email]);
        if (!$q->fetchColumn()) break;
        $email = "sheet.{$slug}.{$n}@local.invalid";
        $n++;
    }
    $randomPassword = bin2hex(random_bytes(16));
    $ins = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,department,status) VALUES(?,?,?,?,?,?)');
    $ins->execute([$name, $email, password_hash($randomPassword, PASSWORD_DEFAULT), 'employee', 'Google Sheet', 'active']);
    return (int)$pdo->lastInsertId();
}

function sheetCategoryId(PDO $pdo, string $name): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $st = $pdo->prepare('SELECT id FROM categories WHERE lower(name)=lower(?) LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;
    $ins = $pdo->prepare('INSERT INTO categories(name,status) VALUES(?,?)');
    $ins->execute([$name, 'active']);
    return (int)$pdo->lastInsertId();
}

function googleSheetSettings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM google_sheet_settings WHERE id=1')->fetch();
    return $row ?: [];
}

function markSheetSync(PDO $pdo, string $status, string $message, int $count = 0): void
{
    $st = $pdo->prepare("UPDATE google_sheet_settings SET last_sync_at=datetime('now','localtime'),last_sync_status=?,last_sync_message=?,last_sync_count=? WHERE id=1");
    $st->execute([$status, $message, $count]);
}

function syncGoogleSheet(PDO $pdo, bool $force = false): array
{
    $settings = googleSheetSettings($pdo);
    $url = trim((string)($settings['sheet_url'] ?? ''));
    if ($url === '') return ['ok'=>true,'skipped'=>true,'message'=>'No Google Sheet is connected yet.'];
    if (!$force && !(int)($settings['enabled'] ?? 0)) return ['ok'=>true,'skipped'=>true,'message'=>'Auto sync is off.'];

    if (!$force && !empty($settings['last_sync_at'])) {
        $last = strtotime((string)$settings['last_sync_at']);
        $interval = max(30, min(3600, (int)($settings['sync_interval'] ?? 60)));
        if ($last && (time() - $last) < $interval) {
            return ['ok'=>true,'skipped'=>true,'message'=>'Already up to date.','last_sync_at'=>$settings['last_sync_at']];
        }
    }

    try {
        $info = parseGoogleSheetUrl($url);
        $csv = httpGetText($info['csv_url']);
        if (stripos($csv, '<html') !== false || stripos($csv, '<!doctype') !== false) {
            throw new RuntimeException('Google returned a login/web page instead of Sheet data. Share the sheet as Anyone with the link - Viewer, then try again.');
        }
        $rows = csvRows($csv);
        if (!$rows) throw new RuntimeException('The Google Sheet returned no rows.');
        [$headerRow, $map] = findSheetHeader($rows);
        $year = (int)($settings['sync_year'] ?? date('Y')) ?: (int)date('Y');
        $parsed = [];
        $lastDate = null;

        for ($i = $headerRow + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $task = sheetCell($row, $map, ['Tasks','Task','Task Description']);
            if ($task === '') continue;

            $dateRaw = sheetCell($row, $map, ['Date','Date.']);
            $date = parseSheetDate($dateRaw, $year);
            if ($date) $lastDate = $date;
            if (!$date) $date = $lastDate;
            if (!$date) continue;

            $client = sheetCell($row, $map, ['Name','Client','Client Name']);
            $type = sheetCell($row, $map, ['Type','Category']);
            $poc = sheetCell($row, $map, ['POC']);
            $contentResponsible = sheetCell($row, $map, ['Content Responsible']);
            $editor = sheetCell($row, $map, ['Responsible editor','Responsible Editor','Editor']);
            $reference = sheetCell($row, $map, ['Reference links','Reference link','References']);
            $timeTaken = sheetCell($row, $map, ['Time Taken ( Videos)','Time Taken (Videos)','Time Taken']);
            $priorityRaw = sheetCell($row, $map, ['Priority']);
            $statusRaw = sheetCell($row, $map, ['Remarks filled by editors','Editor Status','Status']);
            $editorRemarks = sheetCell($row, $map, ['Editors Remarks','Editor Remarks']);
            $accRemark = sheetCell($row, $map, ['Acc manager remark','Account manager remark','Account Manager Remark']);
            $managerRemark = sheetCell($row, $map, ['Manager Remark','Manager Remarks']);
            $day = sheetCell($row, $map, ['Day']) ?: date('l', strtotime($date));

            $employeeName = $editor ?: ($contentResponsible ?: 'Unassigned');
            $parsed[] = [
                'row_number' => $i + 1,
                'date' => $date,
                'day' => $day,
                'client' => $client,
                'type' => $type,
                'poc' => $poc,
                'task' => $task,
                'content_responsible' => $contentResponsible,
                'editor' => $employeeName,
                'reference' => $reference,
                'time_taken' => $timeTaken,
                'priority_raw' => $priorityRaw,
                'priority' => normalizeSheetPriority($priorityRaw),
                'status_raw' => $statusRaw,
                'status' => normalizeSheetStatus($statusRaw),
                'editor_remarks' => $editorRemarks,
                'acc_remark' => $accRemark,
                'manager_remark' => $managerRemark,
            ];
        }

        if (!$parsed) throw new RuntimeException('No task rows could be read. Check the Date and Tasks columns in the connected sheet tab.');

        $pdo->beginTransaction();
        // This app supports one connected Google Sheet. Replace its imported snapshot on each sync.
        $pdo->exec("DELETE FROM tasks WHERE source='google_sheet'");
        $insert = $pdo->prepare(<<<SQL
INSERT INTO tasks(
 employee_id,task_date,task_description,category_id,priority,due_date,status,remarks,
 client_name,task_type,poc,content_responsible,responsible_editor,reference_links,time_taken,
 editor_remarks,acc_manager_remark,manager_remark,sheet_day,raw_status,raw_priority,
 source,source_sheet_key,source_row,synced_at,updated_at
) VALUES(?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))
SQL);

        foreach ($parsed as $item) {
            $employeeId = sheetUserId($pdo, $item['editor']);
            $categoryId = sheetCategoryId($pdo, $item['type']);
            $insert->execute([
                $employeeId,$item['date'],$item['task'],$categoryId,$item['priority'],$item['status'],$item['editor_remarks'],
                $item['client'],$item['type'],$item['poc'],$item['content_responsible'],$item['editor'],$item['reference'],$item['time_taken'],
                $item['editor_remarks'],$item['acc_remark'],$item['manager_remark'],$item['day'],$item['status_raw'],$item['priority_raw'],
                'google_sheet',$info['sheet_key'],$item['row_number']
            ]);
        }
        $pdo->commit();

        $message = count($parsed) . ' tasks synced from Google Sheet.';
        markSheetSync($pdo, 'success', $message, count($parsed));
        return ['ok'=>true,'message'=>$message,'count'=>count($parsed),'sheet_key'=>$info['sheet_key'],'last_sync_at'=>date('Y-m-d H:i:s')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        markSheetSync($pdo, 'error', $e->getMessage(), 0);
        throw $e;
    }
}
