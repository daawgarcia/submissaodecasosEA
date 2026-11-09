<?php
declare(strict_types=1);

namespace EnvioFacil;

require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/DownloadStatus.php';

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    throw new \RuntimeException('Configuração do Envio Fácil não encontrada.');
}
$config = require $configPath;
if (strlen($config['app_key'] ?? '') < 64 || !is_dir($config['private_dir'])) {
    throw new \RuntimeException('Configuração privada inválida.');
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', false);
}
if (PHP_SAPI !== 'cli' && !defined('SHORTINIT')) {
    define('SHORTINIT', true);
}
require_once $config['wordpress'];
global $wpdb;
$wpdb->suppress_errors(true);

function config(string $key): string
{
    global $config;
    return $config[$key];
}

function table(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'enviofacil_' . $name;
}

function query(string $sql, array $args = []): int
{
    global $wpdb;
    $result = $wpdb->query($args ? $wpdb->prepare($sql, $args) : $sql);
    if ($result === false) {
        error_log('[EnvioFacil database] ' . $wpdb->last_error);
        throw new \RuntimeException('Não foi possível acessar o banco de dados.');
    }
    return (int) $result;
}

function rows(string $sql, array $args = []): array
{
    global $wpdb;
    $result = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
    if ($wpdb->last_error !== '') {
        error_log('[EnvioFacil database] ' . $wpdb->last_error);
        throw new \RuntimeException('Não foi possível consultar os registros.');
    }
    return $result;
}

function row(string $sql, array $args = []): ?array
{
    return rows($sql, $args)[0] ?? null;
}

function privatePath(string $relative): string
{
    return config('private_dir') . '/' . $relative;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function token(): string
{
    return bin2hex(random_bytes(32));
}

function digest(string $value): string
{
    return hash_hmac('sha256', $value, config('app_key'));
}

function rateLimit(string $action, int $limit): void
{
    $key = digest($action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . gmdate('Y-m-d-H'));
    $table = table('limits');
    query("INSERT INTO $table (id, hits, expires_at) VALUES (%s, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR)) ON DUPLICATE KEY UPDATE hits = hits + 1", [$key]);
    $record = row("SELECT hits FROM $table WHERE id = %s", [$key]);
    if ((int) $record['hits'] > $limit) {
        throw new HttpError(429, 'Muitas tentativas. Aguarde uma hora e tente novamente.');
    }
}

class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function notify(array $case): bool
{
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    $table = table('cases');
    $id = $case['id'];
    $claimed = query("UPDATE $table SET mail_status = 'sending', mail_attempts = mail_attempts + 1, mail_attempted_at = UTC_TIMESTAMP() WHERE id = %s AND status <> 'draft' AND (mail_status IN ('pending','failed') OR (mail_status = 'sending' AND mail_attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)))", [$id]);
    if (!$claimed) {
        return false;
    }
    try {
        $sent = wp_mail(
            config('notification_email'),
            'Novo caso recebido — ' . $case['protocol'],
            "Um novo caso foi recebido no Envio Fácil.\n\nProtocolo: " . $case['protocol']
                . "\nAcesse o painel protegido para consultar os dados e baixar os arquivos:\n"
                . config('public_url') . "/?page=admin&case=" . rawurlencode($id)
                . "\n\nOs arquivos e dados do paciente não são enviados por e-mail.",
            ['Cc: doc@estheticaligner.com.br', 'Cc: marketing@estheticaligner.com.br']
        );
        if (!$sent) {
            error_log('[EnvioFacil mail] Envio recusado para protocolo ' . $case['protocol']);
        }
        query("UPDATE $table SET mail_status = %s WHERE id = %s", [$sent ? 'sent' : 'failed', $id]);
        return $sent;
    } catch (\Throwable $error) {
        error_log('[EnvioFacil mail] ' . $error->getMessage());
        query("UPDATE $table SET mail_status = 'failed' WHERE id = %s", [$id]);
        return false;
    }
}

function requireAdmin(): array
{
    $id = $_SESSION['admin_id'] ?? '';
    $admin = $id ? row('SELECT id, username, display_name, must_change_password, session_version FROM ' . table('admins') . ' WHERE id = %d', [$id]) : null;
    if (!$admin || time() - ($_SESSION['last_activity'] ?? 0) > 1800
        || (int) $admin['session_version'] !== ($_SESSION['session_version'] ?? 0)) {
        unset($_SESSION['admin_id']);
        throw new HttpError(401, 'Sua sessão expirou. Entre novamente no painel.');
    }
    $_SESSION['last_activity'] = time();
    if ((int) $admin['must_change_password'] === 1 && ($_GET['page'] ?? '') !== 'password') {
        header('Location: ?page=password', true, 303);
        exit;
    }
    return $admin;
}
