<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/google_sheet.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ---------- AUTH ----------
    if ($action === 'login' && $method === 'POST') {
        $in = inputJson();
        $email = strtolower(cleanString($in['email'] ?? '', 180));
        $password = (string)($in['password'] ?? '');
        $st = $pdo->prepare('SELECT id,name,email,password_hash,role,department,status FROM users WHERE email=? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
            jsonResponse(['ok'=>false,'message'=>'Email or password is wrong.'], 401);
        }
        unset($u['password_hash']);
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        csrfToken();
        jsonResponse(['ok'=>true,'user'=>$u,'csrf'=>csrfToken()]);
    }

    if ($action === 'logout' && $method === 'POST') {
        requireLogin();
        requireCsrf();
        $_SESSION = [];
        session_destroy();
        jsonResponse(['ok'=>true]);
    }

    if ($action === 'me') {
        $u = requireLogin();
        jsonResponse(['ok'=>true,'user'=>$u,'csrf'=>csrfToken()]);
    }

    $user = requireLogin();

    // ---------- META ----------
    if ($action === 'meta') {
        if ($user['role'] === 'admin') {
            $users = $pdo->query("SELECT id,name,email,department,role,status FROM users WHERE role='employee' ORDER BY name")->fetchAll();
        } else {
            $users = [$user];
        }
        $cats = $pdo->query("SELECT id,name FROM categories WHERE status='active' ORDER BY name")->fetchAll();
        jsonResponse(['ok'=>true,'users'=>$users,'categories'=>$cats]);
    }

    // ---------- GOOGLE SHEET ----------
    if ($action === 'google_sheet.settings') {
        requireAdmin();
        if ($method === 'GET') {
            $settings = googleSheetSettings($pdo);
            jsonResponse(['ok'=>true,'settings'=>$settings]);
        }
        requireCsrf();
        $in = inputJson();
        $url = cleanString($in['sheet_url'] ?? '', 2000);
        $info = parseGoogleSheetUrl($url);
        $interval = (int)($in['sync_interval'] ?? 60);
        if (!in_array($interval, [30,60,120,300,600], true)) $interval = 60;
        $year = (int)($in['sync_year'] ?? date('Y'));
        if ($year < 2020 || $year > 2100) $year = (int)date('Y');
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $st = $pdo->prepare('UPDATE google_sheet_settings SET sheet_url=?,sheet_id=?,gid=?,sync_interval=?,sync_year=?,enabled=? WHERE id=1');
        $st->execute([$url,$info['sheet_id'],$info['gid'],$interval,$year,$enabled]);
        jsonResponse(['ok'=>true,'message'=>'Google Sheet settings saved.','settings'=>googleSheetSettings($pdo)]);
    }

    if ($action === 'google_sheet.test' && $method === 'POST') {
        requireAdmin();
        requireCsrf();
        $in = inputJson();
        $url = cleanString($in['sheet_url'] ?? '', 2000);
        $info = parseGoogleSheetUrl($url);
        $csv = httpGetText($info['csv_url']);
        if (stripos($csv, '<html') !== false || stripos($csv, '<!doctype') !== false) {
            jsonResponse(['ok'=>false,'message'=>'Google returned a web/login page. Share the sheet as Anyone with the link - Viewer and try again.'], 422);
        }
        $rows = csvRows($csv);
        [$headerRow, $map] = findSheetHeader($rows);
        $taskRows = 0;
        for ($i=$headerRow+1; $i<count($rows); $i++) {
            if (sheetCell($rows[$i], $map, ['Tasks','Task','Task Description']) !== '') $taskRows++;
        }
        jsonResponse([
            'ok'=>true,
            'message'=>'Connection successful. The sheet can be read.',
            'task_rows'=>$taskRows,
            'gid'=>$info['gid'],
            'headers'=>array_keys($map),
        ]);
    }

    if ($action === 'google_sheet.sync' && $method === 'POST') {
        requireCsrf();
        $in = inputJson();
        $force = !empty($in['force']) && $user['role'] === 'admin';
        $result = syncGoogleSheet($pdo, $force);
        jsonResponse($result);
    }

    if ($action === 'google_sheet.status') {
        $settings = googleSheetSettings($pdo);
        $data = [
            'enabled'=>(int)($settings['enabled'] ?? 0),
            'connected'=>!empty($settings['sheet_url']),
            'last_sync_at'=>$settings['last_sync_at'] ?? null,
            'last_sync_status'=>$settings['last_sync_status'] ?? null,
            'last_sync_message'=>$settings['last_sync_message'] ?? null,
            'last_sync_count'=>(int)($settings['last_sync_count'] ?? 0),
            'sync_interval'=>(int)($settings['sync_interval'] ?? 60),
        ];
        if ($user['role'] === 'admin') $data['sheet_url'] = $settings['sheet_url'] ?? '';
        jsonResponse(['ok'=>true,'status'=>$data]);
    }

    // ---------- TASKS ----------
    if ($action === 'tasks') {
        if ($method === 'GET') {
            $params = [];
            $where = ' WHERE 1=1 ';
            if ($user['role'] !== 'admin') {
                $where .= ' AND t.employee_id=?';
                $params[] = (int)$user['id'];
            }
            if (!empty($_GET['search'])) {
                $where .= ' AND (t.task_description LIKE ? OR t.remarks LIKE ? OR u.name LIKE ? OR t.client_name LIKE ? OR t.poc LIKE ? OR t.content_responsible LIKE ?)';
                $s = '%' . cleanString($_GET['search'], 120) . '%';
                array_push($params, $s,$s,$s,$s,$s,$s);
            }
            foreach (['status'=>'t.status','priority'=>'t.priority','source'=>'t.source'] as $key=>$col) {
                if (!empty($_GET[$key])) {
                    $where .= " AND $col=?";
                    $params[] = cleanString($_GET[$key], 40);
                }
            }
            if (!empty($_GET['employee_id']) && $user['role'] === 'admin') {
                $where .= ' AND t.employee_id=?';
                $params[] = (int)$_GET['employee_id'];
            }
            if (!empty($_GET['from'])) { $where .= ' AND t.task_date>=?'; $params[] = cleanString($_GET['from'],10); }
            if (!empty($_GET['to'])) { $where .= ' AND t.task_date<=?'; $params[] = cleanString($_GET['to'],10); }

            $allowed = [
                'task_date'=>'t.task_date','due_date'=>'t.due_date','priority'=>'t.priority',
                'status'=>'t.status','created_at'=>'t.created_at','client'=>'t.client_name'
            ];
            $sort = $allowed[$_GET['sort'] ?? 'task_date'] ?? 't.task_date';
            $dir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
            $st = $pdo->prepare("SELECT t.*,u.name employee_name,c.name category_name FROM tasks t JOIN users u ON u.id=t.employee_id LEFT JOIN categories c ON c.id=t.category_id $where ORDER BY $sort $dir,t.id DESC");
            $st->execute($params);
            jsonResponse(['ok'=>true,'tasks'=>$st->fetchAll()]);
        }

        requireCsrf();
        $in = inputJson();

        if ($method === 'POST') {
            $employeeId = $user['role'] === 'admin' ? (int)($in['employee_id'] ?? $user['id']) : (int)$user['id'];
            $desc = cleanString($in['task_description'] ?? '', 2000);
            if ($desc === '') jsonResponse(['ok'=>false,'message'=>'Task description is required.'], 422);
            $taskDate = cleanString($in['task_date'] ?? date('Y-m-d'), 10);
            $priority = cleanString($in['priority'] ?? 'Medium', 20);
            $status = cleanString($in['status'] ?? 'Not Started', 30);
            if (!in_array($priority,['Low','Medium','High','Critical'],true)) $priority='Medium';
            if (!in_array($status,['Not Started','In Progress','Completed','Pending','Blocked','Cancelled'],true)) $status='Not Started';
            $st = $pdo->prepare("INSERT INTO tasks(employee_id,task_date,task_description,category_id,priority,due_date,status,remarks,source,updated_at) VALUES(?,?,?,?,?,?,?,?, 'manual',datetime('now','localtime'))");
            $st->execute([$employeeId,$taskDate,$desc,($in['category_id']??'')!==''?(int)$in['category_id']:null,$priority,cleanString($in['due_date']??'',30)?:null,$status,cleanString($in['remarks']??'',2000)]);
            jsonResponse(['ok'=>true,'message'=>'Task added.','id'=>(int)$pdo->lastInsertId()], 201);
        }

        if ($method === 'PUT') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonResponse(['ok'=>false,'message'=>'Task ID is required.'],422);
            $check = $pdo->prepare('SELECT employee_id,source FROM tasks WHERE id=?');
            $check->execute([$id]);
            $task = $check->fetch();
            if (!$task) jsonResponse(['ok'=>false,'message'=>'Task not found.'],404);
            if (($task['source'] ?? 'manual') === 'google_sheet') jsonResponse(['ok'=>false,'message'=>'This task comes from Google Sheet. Edit it in the Google Sheet; it will sync automatically.'],409);
            if ($user['role'] !== 'admin' && (int)$task['employee_id'] !== (int)$user['id']) jsonResponse(['ok'=>false,'message'=>'You can edit only your own tasks.'],403);
            $employeeId = $user['role']==='admin' ? (int)($in['employee_id'] ?? $task['employee_id']) : (int)$user['id'];
            $priority = cleanString($in['priority'] ?? 'Medium',20);
            $status = cleanString($in['status'] ?? 'Not Started',30);
            if (!in_array($priority,['Low','Medium','High','Critical'],true)) $priority='Medium';
            if (!in_array($status,['Not Started','In Progress','Completed','Pending','Blocked','Cancelled'],true)) $status='Not Started';
            $st = $pdo->prepare("UPDATE tasks SET employee_id=?,task_date=?,task_description=?,category_id=?,priority=?,due_date=?,status=?,remarks=?,updated_at=datetime('now','localtime') WHERE id=?");
            $st->execute([$employeeId,cleanString($in['task_date']??date('Y-m-d'),10),cleanString($in['task_description']??'',2000),($in['category_id']??'')!==''?(int)$in['category_id']:null,$priority,cleanString($in['due_date']??'',30)?:null,$status,cleanString($in['remarks']??'',2000),$id]);
            jsonResponse(['ok'=>true,'message'=>'Task updated.']);
        }

        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            $check = $pdo->prepare('SELECT employee_id,source FROM tasks WHERE id=?');
            $check->execute([$id]);
            $task = $check->fetch();
            if (!$task) jsonResponse(['ok'=>false,'message'=>'Task not found.'],404);
            if (($task['source'] ?? 'manual') === 'google_sheet') jsonResponse(['ok'=>false,'message'=>'This task comes from Google Sheet. Delete it in the Google Sheet; it will sync automatically.'],409);
            if ($user['role'] !== 'admin' && (int)$task['employee_id'] !== (int)$user['id']) jsonResponse(['ok'=>false,'message'=>'You can delete only your own tasks.'],403);
            $st = $pdo->prepare('DELETE FROM tasks WHERE id=?');
            $st->execute([$id]);
            jsonResponse(['ok'=>true,'message'=>'Task deleted.']);
        }
    }

    // ---------- USERS ----------
    if ($action === 'users') {
        requireAdmin();
        if ($method === 'GET') {
            jsonResponse(['ok'=>true,'users'=>$pdo->query('SELECT id,name,email,role,department,status,created_at FROM users ORDER BY name')->fetchAll()]);
        }
        requireCsrf();
        $in = inputJson();
        if ($method === 'POST') {
            $name = cleanString($in['name'] ?? '',120);
            $email = strtolower(cleanString($in['email'] ?? '',180));
            $pass = (string)($in['password'] ?? '');
            if (!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pass)<6) jsonResponse(['ok'=>false,'message'=>'Enter name, valid email and password of at least 6 characters.'],422);
            try {
                $st=$pdo->prepare('INSERT INTO users(name,email,password_hash,role,department,status) VALUES(?,?,?,?,?,?)');
                $st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),($in['role']??'employee')==='admin'?'admin':'employee',cleanString($in['department']??'',120),($in['status']??'active')==='inactive'?'inactive':'active']);
            } catch(PDOException) {
                jsonResponse(['ok'=>false,'message'=>'This email already exists.'],409);
            }
            jsonResponse(['ok'=>true,'message'=>'User added.'],201);
        }
        if ($method === 'PUT') {
            $id=(int)($_GET['id']??0);
            $pass=(string)($in['password']??'');
            if ($pass!=='') {
                $st=$pdo->prepare('UPDATE users SET name=?,email=?,password_hash=?,role=?,department=?,status=? WHERE id=?');
                $st->execute([cleanString($in['name'],120),strtolower(cleanString($in['email'],180)),password_hash($pass,PASSWORD_DEFAULT),($in['role']??'employee')==='admin'?'admin':'employee',cleanString($in['department']??'',120),($in['status']??'active')==='inactive'?'inactive':'active',$id]);
            } else {
                $st=$pdo->prepare('UPDATE users SET name=?,email=?,role=?,department=?,status=? WHERE id=?');
                $st->execute([cleanString($in['name'],120),strtolower(cleanString($in['email'],180)),($in['role']??'employee')==='admin'?'admin':'employee',cleanString($in['department']??'',120),($in['status']??'active')==='inactive'?'inactive':'active',$id]);
            }
            jsonResponse(['ok'=>true,'message'=>'User updated.']);
        }
    }

    // ---------- ANALYTICS ----------
    if (str_starts_with($action,'analytics.')) {
        [$from,$to] = dateRange($_GET);
        $scope = '';
        $scopeParams = [];
        if ($user['role'] !== 'admin') {
            $scope = ' AND t.employee_id=?';
            $scopeParams[] = (int)$user['id'];
        }
        $baseParams = array_merge([$from,$to],$scopeParams);

        if ($action === 'analytics.dashboard') {
            $sql = "SELECT COUNT(*) total,
                SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,
                SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) in_progress,
                SUM(CASE WHEN status='Blocked' THEN 1 ELSE 0 END) blocked,
                SUM(CASE WHEN due_date IS NOT NULL AND due_date<>'' AND due_date < datetime('now','localtime') AND status NOT IN ('Completed','Cancelled') THEN 1 ELSE 0 END) overdue,
                SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible
                FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope";
            $st=$pdo->prepare($sql); $st->execute($baseParams); $r=$st->fetch() ?: [];
            $eligible=(int)($r['eligible']??0); $completed=(int)($r['completed']??0);
            $completion=$eligible?round($completed*100/$eligible,1):0;
            $prodSql="SELECT SUM(CASE WHEN status='Completed' THEN 1 WHEN status='In Progress' THEN .5 ELSE 0 END) score, SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope";
            $ps=$pdo->prepare($prodSql); $ps->execute($baseParams); $pr=$ps->fetch() ?: [];
            $prod=(int)($pr['eligible']??0)?round(((float)($pr['score']??0))*100/(int)$pr['eligible'],1):0;
            jsonResponse(['ok'=>true,'range'=>[$from,$to],'data'=>[
                'total'=>(int)($r['total']??0),'completed'=>$completed,'pending'=>(int)($r['pending']??0),
                'in_progress'=>(int)($r['in_progress']??0),'blocked'=>(int)($r['blocked']??0),
                'overdue'=>(int)($r['overdue']??0),'completion'=>$completion,'productivity'=>$prod
            ]]);
        }

        if ($action==='analytics.employees' || $action==='analytics.todayEmployees') {
            if ($action==='analytics.todayEmployees') $from=$to=date('Y-m-d');
            $params=[$from,$to];
            $where=" AND u.role='employee'";
            if ($user['role']!=='admin') { $where.=' AND u.id=?'; $params[]=(int)$user['id']; }
            $st=$pdo->prepare("SELECT u.id,u.name,u.department,
                COUNT(t.id) total,
                SUM(CASE WHEN t.status='Completed' THEN 1 ELSE 0 END) completed,
                SUM(CASE WHEN t.status='Pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN t.status='In Progress' THEN 1 ELSE 0 END) in_progress,
                SUM(CASE WHEN t.status<>'Cancelled' THEN 1 ELSE 0 END) eligible
                FROM users u LEFT JOIN tasks t ON t.employee_id=u.id AND t.task_date BETWEEN ? AND ?
                WHERE u.status='active' $where GROUP BY u.id ORDER BY u.name");
            $st->execute($params);
            $rows=[];
            foreach($st->fetchAll() as $r){
                $p=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;
                $r['completion']=$p; $r['level']=perfLevel($p); $rows[]=$r;
            }
            usort($rows,fn($a,$b)=>$b['completion']<=>$a['completion']);
            foreach($rows as $i=>&$r) $r['rank']=$i+1;
            jsonResponse(['ok'=>true,'employees'=>$rows]);
        }

        if ($action==='analytics.daily') {
            $st=$pdo->prepare("SELECT task_date date,COUNT(*) total,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) pending,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope GROUP BY task_date ORDER BY task_date");
            $st->execute($baseParams); $rows=[];
            foreach($st->fetchAll() as $r){$r['completion']=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;$rows[]=$r;}
            jsonResponse(['ok'=>true,'daily'=>$rows]);
        }

        if ($action==='analytics.status') {
            $st=$pdo->prepare("SELECT status label,COUNT(*) value FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope AND status<>'Cancelled' GROUP BY status ORDER BY value DESC");
            $st->execute($baseParams); jsonResponse(['ok'=>true,'items'=>$st->fetchAll()]);
        }

        if ($action==='analytics.categories') {
            $st=$pdo->prepare("SELECT COALESCE(c.name,'Uncategorized') label,COUNT(*) total,SUM(CASE WHEN t.status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN t.status='Pending' THEN 1 ELSE 0 END) pending,SUM(CASE WHEN t.status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks t LEFT JOIN categories c ON c.id=t.category_id WHERE t.task_date BETWEEN ? AND ? $scope GROUP BY COALESCE(c.name,'Uncategorized') ORDER BY total DESC");
            $st->execute($baseParams); $rows=[];
            foreach($st->fetchAll() as $r){$r['completion']=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;$rows[]=$r;}
            jsonResponse(['ok'=>true,'items'=>$rows]);
        }

        if ($action==='analytics.clients') {
            $st=$pdo->prepare("SELECT COALESCE(NULLIF(client_name,''),'Manual / No Client') label,COUNT(*) total,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope GROUP BY COALESCE(NULLIF(client_name,''),'Manual / No Client') ORDER BY total DESC LIMIT 30");
            $st->execute($baseParams); $rows=[];
            foreach($st->fetchAll() as $r){$r['completion']=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;$rows[]=$r;}
            jsonResponse(['ok'=>true,'items'=>$rows]);
        }

        if ($action==='analytics.priorities') {
            $st=$pdo->prepare("SELECT priority label,COUNT(*) total,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks t WHERE t.task_date BETWEEN ? AND ? $scope GROUP BY priority");
            $st->execute($baseParams); $rows=[];
            foreach($st->fetchAll() as $r){$r['completion']=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;$rows[]=$r;}
            jsonResponse(['ok'=>true,'items'=>$rows]);
        }

        if ($action==='analytics.overdue') {
            $st=$pdo->prepare("SELECT t.id,u.name employee_name,t.task_description,t.due_date,t.priority,t.status FROM tasks t JOIN users u ON u.id=t.employee_id WHERE t.task_date BETWEEN ? AND ? $scope AND t.due_date IS NOT NULL AND t.due_date<>'' AND t.due_date < datetime('now','localtime') AND t.status NOT IN ('Completed','Cancelled') ORDER BY t.due_date LIMIT 100");
            $st->execute($baseParams); jsonResponse(['ok'=>true,'tasks'=>$st->fetchAll()]);
        }

        if ($action==='analytics.employee') {
            $employeeId=$user['role']==='admin'?(int)($_GET['employee_id']??0):(int)$user['id'];
            if(!$employeeId) jsonResponse(['ok'=>false,'message'=>'Select employee.'],422);
            $st=$pdo->prepare("SELECT task_date date,COUNT(*) total,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks WHERE employee_id=? AND task_date BETWEEN ? AND ? GROUP BY task_date ORDER BY task_date");
            $st->execute([$employeeId,$from,$to]); $daily=[];
            foreach($st->fetchAll() as $r){$r['completion']=(int)$r['eligible']?round((int)$r['completed']*100/(int)$r['eligible'],1):0;$daily[]=$r;}
            $sum=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) completed,SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) pending,SUM(CASE WHEN status<>'Cancelled' THEN 1 ELSE 0 END) eligible FROM tasks WHERE employee_id=? AND task_date BETWEEN ? AND ?");
            $sum->execute([$employeeId,$from,$to]); $r=$sum->fetch() ?: [];
            $comp=(int)($r['eligible']??0)?round((int)($r['completed']??0)*100/(int)$r['eligible'],1):0;
            jsonResponse(['ok'=>true,'summary'=>['total'=>(int)($r['total']??0),'completed'=>(int)($r['completed']??0),'pending'=>(int)($r['pending']??0),'completion'=>$comp],'daily'=>$daily]);
        }
    }

    jsonResponse(['ok'=>false,'message'=>'API action not found.'],404);
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    jsonResponse(['ok'=>false,'message'=>'Server error: '.$e->getMessage()],500);
}
