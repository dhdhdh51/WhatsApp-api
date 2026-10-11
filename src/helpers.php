<?php
declare(strict_types=1);

/** Small global helpers used by the views. */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Bilingual label: "English <small>हिंदी</small>" (already escaped). */
function L(string $en, string $hi): string
{
    return e($en) . ' <small class="hi">' . e($hi) . '</small>';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $type = null, ?string $msg = null): array
{
    if ($type !== null) {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => (string) $msg];
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function q(string $key, mixed $default = ''): mixed
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function p(string $key, mixed $default = ''): mixed
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function valid_date(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function date_or(?string $d, string $default): string
{
    return valid_date($d) ? (string) $d : $default;
}

function fmt_date(?string $d): string
{
    return $d ? date('d-m-Y', strtotime($d)) : '';
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d-m-Y h:i A', strtotime($dt)) : '';
}

function fmt_time12(string $hhmm): string
{
    return date('h:i A', strtotime('2000-01-01 ' . $hhmm));
}

/** Builds a query string preserving current GET params, overriding some. */
function qs(array $override = []): string
{
    $params = array_merge($_GET, $override);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return $params ? '?' . http_build_query($params) : '';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
