<?php
declare(strict_types=1);

use function EnvioFacil\{config, privatePath, token, digest, table, row, rows, query, e, rateLimit, requireAdmin, notify};
use EnvioFacil\HttpError;

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store, private');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Strict-Transport-Security: max-age=31536000');

set_exception_handler(function (Throwable $error): void {
    $status = $error instanceof HttpError ? $error->status : ($error instanceof InvalidArgumentException ? 422 : 500);
    if ($status === 500) {
        error_log('[EnvioFacil] ' . get_class($error) . ': ' . $error->getMessage());
    }
    http_response_code($status);
    $message = $status === 500 ? 'Não foi possível concluir a operação. Tente novamente ou contate a equipe.' : $error->getMessage();
    if (isset($_GET['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Envio Fácil</title><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="?">Voltar ao início</a></html>';
    }
});

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Upload.php';

if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    throw new HttpError(400, 'Acesse o portal usando HTTPS para proteger os dados.');
}
session_name('enviofacil_session');
session_save_path(privatePath('sessions'));
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
if (!session_start()) {
    throw new RuntimeException('Não foi possível iniciar a sessão protegida.');
}
$_SESSION['csrf'] ??= token();

function csrfCheck(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($provided) || !hash_equals($_SESSION['csrf'], $provided)) {
        throw new HttpError(403, 'A sessão do formulário expirou. Atualize a página.');
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'upload';
$error = '';

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        throw new HttpError(405, 'Use POST para enviar arquivos.');
    }
    csrfCheck();
    $data = [];
    if ($action !== 'chunk') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new HttpError(413, 'Formulário acima do tamanho permitido.');
        }
        try {
            $data = json_decode(file_get_contents('php://input', false, null, 0, 16385), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpError(400, 'Formulário inválido.');
        }
        if (!is_array($data)) {
            throw new HttpError(400, 'Formulário inválido.');
        }
    }
    $result = match ($action) {
        'create' => EnvioFacil\createCase($data),
        'begin-file' => EnvioFacil\beginFile(EnvioFacil\publicCase(), $data),
        'chunk' => EnvioFacil\uploadChunk(EnvioFacil\publicCase()),
        'complete-file' => EnvioFacil\completeFile(EnvioFacil\publicCase(), $data),
        'submit' => EnvioFacil\submitCase(EnvioFacil\publicCase(true)),
        default => throw new HttpError(404, 'Operação não encontrada.'),
    };
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        $operation = $_POST['operation'] ?? '';
        if ($operation === 'setup') {
            rateLimit('setup', 10);
            $setupPath = privatePath('setup.json');
            $setup = is_file($setupPath) ? json_decode(file_get_contents($setupPath), true, 8, JSON_THROW_ON_ERROR) : null;
            if (!$setup || $setup['expires'] < time() || !hash_equals($setup['hash'], digest((string) ($_POST['setup_token'] ?? '')))) {
                throw new HttpError(403, 'Código de ativação inválido ou expirado.');
            }
            $lock = fopen(privatePath('setup.lock'), 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('Não foi possível reservar a ativação.');
            }
            try {
                if (row('SELECT id FROM ' . table('admins') . ' LIMIT 1')) {
                    throw new HttpError(409, 'O painel já foi ativado.');
                }
                $username = strtolower(trim((string) ($_POST['username'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username) || strlen($password) < 14 || strlen($password) > 72) {
                    throw new HttpError(422, 'Informe um usuário válido e uma senha de 14 a 72 caracteres.');
                }
                query('INSERT INTO ' . table('admins') . ' (username, display_name, password_hash, must_change_password) VALUES (%s,%s,%s,0)', [$username, $username, password_hash($password, PASSWORD_DEFAULT)]);
                if (!unlink($setupPath)) {
                    error_log('[EnvioFacil setup] Não foi possível remover código já utilizado.');
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $_SESSION['flash'] = 'Painel ativado. Entre com o usuário e a senha que você definiu.';
            redirect('?page=login');
        } elseif ($operation === 'login') {
            rateLimit('login', 15);
            $admin = row('SELECT * FROM ' . table('admins') . ' WHERE username = %s', [strtolower(trim((string) ($_POST['username'] ?? '')))]);
            $valid = password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$admin || !$valid) {
                throw new HttpError(401, 'Usuário ou senha incorretos.');
            }
            session_regenerate_id(true);
            $_SESSION['csrf'] = token();
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['session_version'] = (int) $admin['session_version'];
            $_SESSION['last_activity'] = time();
            redirect('?page=admin');
        } else {
            $admin = requireAdmin();
            if ($operation === 'logout') {
                $_SESSION = [];
                session_destroy();
                redirect('?page=login');
            }
            if ($operation === 'password') {
                rateLimit('password', 15);
                $stored = row('SELECT password_hash FROM ' . table('admins') . ' WHERE id = %d', [$admin['id']]);
                $password = (string) ($_POST['password'] ?? '');
                if (!password_verify((string) ($_POST['current_password'] ?? ''), $stored['password_hash'])) {
                    throw new HttpError(422, 'A senha atual está incorreta.');
                }
                if (strlen($password) < 14 || strlen($password) > 72 || $password !== ($_POST['confirmation'] ?? '')
                    || password_verify($password, $stored['password_hash']) || str_contains(strtolower($password), strtolower($admin['username']))) {
                    throw new HttpError(422, 'Use uma senha nova de 14 a 72 caracteres, sem seu nome de usuário, e repita a confirmação.');
                }
                query('UPDATE ' . table('admins') . ' SET password_hash = %s, must_change_password = 0, session_version = session_version + 1 WHERE id = %d', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
                $version = row('SELECT session_version FROM ' . table('admins') . ' WHERE id = %d', [$admin['id']]);
                session_regenerate_id(true);
                $_SESSION['session_version'] = (int) $version['session_version'];
                $_SESSION['csrf'] = token();
                $_SESSION['flash'] = 'Sua senha foi atualizada.';
                redirect('?page=admin');
            }
            $case = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s AND status <> \'draft\'', [$_POST['case'] ?? '']);
            if (!$case) {
                throw new HttpError(404, 'Caso não encontrado.');
            }
            if ($operation === 'status') {
                $status = $_POST['status'] ?? '';
                if (!in_array($status, ['received', 'processing', 'submitted'], true)) {
                    throw new HttpError(422, 'Situação inválida.');
                }
                query('UPDATE ' . table('cases') . ' SET status = %s WHERE id = %s', [$status, $case['id']]);
                $_SESSION['flash'] = 'Situação do caso atualizada.';
            } elseif ($operation === 'retry-mail') {
                $queued = query('UPDATE ' . table('cases') . ' SET mail_status = \'pending\', mail_attempts = 0 WHERE id = %s AND mail_status IN (\'pending\',\'failed\')', [$case['id']]);
                $_SESSION['flash'] = $queued ? 'Notificação colocada na fila. O envio será tentado pela rotina automática em até cinco minutos.' : 'A notificação já foi enviada ou está em processamento.';
            } else {
                throw new HttpError(400, 'Operação inválida.');
            }
            redirect('?page=admin&case=' . rawurlencode($case['id']));
        }
    } catch (HttpError $exception) {
        http_response_code($exception->status);
        $error = $exception->getMessage();
    }
}

if ($page === 'download') {
    $admin = requireAdmin();
    $file = row('SELECT f.*, c.status FROM ' . table('files') . ' f JOIN ' . table('cases') . ' c ON c.id = f.case_id WHERE f.id = %s AND c.status <> \'draft\' AND f.verified = 1', [$_GET['file'] ?? '']);
    if (!$file) {
        throw new HttpError(404, 'Arquivo não encontrado.');
    }
    $path = privatePath('uploads/' . $file['case_id'] . '/' . $file['id']);
    if (!is_file($path) || filesize($path) !== (int) $file['size']) {
        throw new RuntimeException('Arquivo ausente ou incompleto no armazenamento privado.');
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Não foi possível abrir o arquivo.');
    }
    query('START TRANSACTION');
    try {
        query('INSERT INTO ' . table('downloads') . ' (file_id, admin_id, admin_name, started_at) VALUES (%s,%d,%s,UTC_TIMESTAMP())', [$file['id'], $admin['id'], $admin['display_name']]);
        global $wpdb;
        $downloadId = (int) $wpdb->insert_id;
        query('UPDATE ' . table('files') . ' SET last_download_by = %s, last_download_at = UTC_TIMESTAMP() WHERE id = %s', [$admin['display_name'], $file['id']]);
        query('COMMIT');
    } catch (Throwable $exception) {
        query('ROLLBACK');
        fclose($handle);
        throw $exception;
    }
    session_write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $file['size']);
    header("Content-Disposition: attachment; filename=\"arquivo." . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) . "\"; filename*=UTF-8''" . rawurlencode($file['name']));
    ignore_user_abort(true);
    $sent = fpassthru($handle);
    fclose($handle);
    query('UPDATE ' . table('downloads') . ' SET transfer_status = %s WHERE id = %d', [$sent === (int) $file['size'] && !connection_aborted() ? 'streamed' : 'interrupted', $downloadId]);
    exit;
}

if ($page === 'admin' || $page === 'password') {
    try {
        $admin = requireAdmin();
    } catch (HttpError $exception) {
        redirect('?page=login');
    }
}
if (!in_array($page, ['upload', 'admin', 'login', 'setup', 'privacy', 'password'], true)) {
    throw new HttpError(404, 'Página não encontrada.');
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
require dirname(__DIR__) . '/src/views.php';
