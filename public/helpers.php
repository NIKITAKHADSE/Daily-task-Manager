<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function inputJson(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function requireLogin(): array {
    $u = currentUser();
    if (!$u) jsonResponse(['ok'=>false,'message'=>'Please login first.'],401);
    return $u;
}
function requireAdmin(): array {
    $u = requireLogin();
    if (($u['role'] ?? '') !== 'admin') jsonResponse(['ok'=>false,'message'=>'Admin access required.'],403);
    return $u;
}
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function requireCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) jsonResponse(['ok'=>false,'message'=>'Security token expired. Refresh the page and try again.'],419);
}
function cleanString(mixed $v, int $max=5000): string {
    $text = trim((string)$v);
    return function_exists('mb_substr') ? mb_substr($text,0,$max) : substr($text,0,$max);
}
function dateRange(array $q): array {
    $range = $q['range'] ?? 'this_month';
    $today = new DateTimeImmutable('today');
    switch ($range) {
        case 'today': $from=$to=$today; break;
        case 'yesterday': $from=$to=$today->modify('-1 day'); break;
        case 'this_week': $from=$today->modify('monday this week'); $to=$today->modify('sunday this week'); break;
        case 'last_week': $from=$today->modify('monday last week'); $to=$today->modify('sunday last week'); break;
        case 'last_month': $from=$today->modify('first day of last month'); $to=$today->modify('last day of last month'); break;
        case 'custom':
            $from = DateTimeImmutable::createFromFormat('Y-m-d', (string)($q['from'] ?? '')) ?: $today;
            $to = DateTimeImmutable::createFromFormat('Y-m-d', (string)($q['to'] ?? '')) ?: $today;
            if ($from > $to) [$from,$to]=[$to,$from];
            break;
        default: $from=$today->modify('first day of this month'); $to=$today->modify('last day of this month');
    }
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}
function taskScope(array $user, string $alias='t'): array {
    if ($user['role']==='admin') return ['',[]];
    return [" AND {$alias}.employee_id = ?", [(int)$user['id']]];
}
function perfLevel(float $p): string {
    if ($p >= 90) return 'Excellent';
    if ($p >= 75) return 'Good';
    if ($p >= 50) return 'Average';
    return 'Needs Improvement';
}
