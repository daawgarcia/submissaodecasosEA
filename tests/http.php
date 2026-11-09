<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
});
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, 'Fatal: ' . $error['message'] . "\n");
    }
});

use function EnvioFacil\{table, query, row, rows, privatePath};

$url = EnvioFacil\config('public_url') . '/';
$cookie = tempnam(sys_get_temp_dir(), 'envio-http-');
$csrf = '';
$uploadToken = '';
$ids = [];
$checks = 0;
$username = 'teste-' . bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(20));
$adminId = null;
$testFile = null;

function assertHttp(bool $valid, string $label): void
{
    global $checks;
    if (!$valid) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo "OK: $label\n";
}

function http(string $queryString = '', mixed $data = null, bool $raw = false, bool $useCsrf = true, bool $useCookie = true): array
{
    global $url, $cookie, $csrf, $uploadToken;
    $handle = curl_init($url . ($queryString ? '?' . $queryString : ''));
    $headers = [];
    if ($useCsrf) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($uploadToken) {
        $headers[] = 'X-Upload-Token: ' . $uploadToken;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $originIp = getenv('ENVIOFACIL_TEST_IP');
    if ($originIp !== false && $originIp !== '') {
        curl_setopt($handle, CURLOPT_RESOLVE, ['enviofacil.estheticaligner.com.br:443:' . $originIp]);
    }
    if ($useCookie) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookie);
    }
    if ($data !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        if (is_array($data) && str_contains($queryString, 'action=')) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($data, JSON_THROW_ON_ERROR);
        } elseif (is_array($data)) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($data);
        } else {
            $headers[] = 'Content-Type: application/octet-stream';
            $body = $data;
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $body = curl_exec($handle);
    if ($body === false) {
        throw new RuntimeException('Erro HTTP: ' . curl_error($handle));
    }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => $decoded];
}

function refreshCsrf(string $page = ''): void
{
    global $csrf;
    $response = http($page);
    assertHttp($response['status'] === 200, 'página acessível: ' . ($page ?: 'formulário'));
    assertHttp((bool) preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $response['body'], $match), 'token CSRF disponível');
    $csrf = $match[1];
}

try {
    refreshCsrf();
    assertHttp(http('action=create', [], false, false)['status'] === 403, 'rejeita requisição sem CSRF');
    $invalid = http('action=create', ['dentist' => 'Maria', 'patient' => 'Paciente Teste', 'whatsapp' => '5511999991234', 'consent' => true]);
    assertHttp($invalid['status'] === 422, 'exige nome completo no servidor (HTTP ' . $invalid['status'] . ', resposta: ' . substr($invalid['body'], 0, 300) . ')');
    $data = ['dentist' => 'Dentista Teste Integração', 'patient' => 'Paciente Fictício Integração', 'whatsapp' => '5511999991234', 'consent' => true];
    $created = http('action=create', $data);
    assertHttp($created['status'] === 200 && !empty($created['json']['token']), 'cria caso com autorização privada');
    $case = $created['json']['id'];
    $ids[] = $case;
    $uploadToken = $created['json']['token'];
    assertHttp(http("action=submit&case=$case", [])['status'] === 422, 'rejeita caso sem arquivos');
    assertHttp(http("action=begin-file&case=$case", ['name' => 'script.php', 'size' => 100])['status'] === 422, 'rejeita extensão executável');
    $bytes = str_repeat("\0", 80) . pack('V', 1) . str_repeat("\0", 50);
    $begin = http("action=begin-file&case=$case", ['name' => 'arcada-teste.stl', 'size' => strlen($bytes)]);
    assertHttp($begin['status'] === 200, 'reserva arquivo STL');
    $testFile = $begin['json']['id'];
    $uploadToken = 'invalid';
    assertHttp(http("action=chunk&case=$case&file=$testFile&offset=0", $bytes, true)['status'] === 403, 'rejeita acesso de outro remetente');
    $uploadToken = $created['json']['token'];
    assertHttp(http("action=chunk&case=$case&file=$testFile&offset=1", $bytes, true)['status'] === 422, 'rejeita bloco acima do tamanho declarado');
    assertHttp(http("action=chunk&case=$case&file=$testFile&offset=0", $bytes, true)['status'] === 200, 'recebe bloco');
    assertHttp(http("action=chunk&case=$case&file=$testFile&offset=0", $bytes, true)['status'] === 200, 'repetição de bloco é idempotente');
    assertHttp(http("action=chunk&case=$case&file=$testFile&offset=0", str_repeat('x', strlen($bytes)), true)['status'] === 409, 'rejeita repetição com dados diferentes');
    assertHttp(http("action=submit&case=$case", [])['status'] === 422, 'exige validação do arquivo');
    assertHttp(http("action=complete-file&case=$case", ['file' => $testFile])['status'] === 200, 'valida STL recebido');
    $submitted = http("action=submit&case=$case", []);
    assertHttp($submitted['status'] === 200 && !empty($submitted['json']['protocol']), 'conclui caso e gera protocolo');
    assertHttp(http("action=submit&case=$case", [])['status'] === 200, 'conclusão repetida é idempotente');
    $record = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s', [$case]);
    assertHttp($record['status'] === 'received' && (int) $record['mail_attempts'] === 0, 'salva caso e mantém uma notificação na fila');
    EnvioFacil\notify($record);
    $record = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s', [$case]);
    assertHttp((int) $record['mail_attempts'] === 1, 'rotina privada tenta notificar uma vez');
    echo 'E-mail de teste: ' . $record['mail_status'] . ' / ' . $record['protocol'] . PHP_EOL;
    assertHttp(http("page=download&file=$testFile", null, false, false, false)['status'] === 401, 'download exige autenticação');
    $limitCase = http('action=create', $data);
    assertHttp($limitCase['status'] === 200, 'cria caso para testar limites');
    $limitId = $limitCase['json']['id'];
    $ids[] = $limitId;
    $uploadToken = $limitCase['json']['token'];
    assertHttp(http("action=begin-file&case=$limitId", ['name' => 'limite.stl', 'size' => EnvioFacil\MAX_CASE_BYTES + 1])['status'] === 422, 'rejeita 500 MiB mais um byte');
    assertHttp(http("action=begin-file&case=$limitId", ['name' => 'limite.stl', 'size' => EnvioFacil\MAX_CASE_BYTES])['status'] === 200, 'aceita exatamente 500 MiB declarados');
    assertHttp(http("action=begin-file&case=$limitId", ['name' => 'extra.stl', 'size' => 1])['status'] === 422, 'limite total considera arquivos reservados');
    query('DELETE FROM ' . table('files') . ' WHERE case_id = %s', [$limitId]);
    for ($i = 0; $i < 20; $i++) {
        $response = http("action=begin-file&case=$limitId", ['name' => "arquivo-$i.stl", 'size' => 1]);
        assertHttp($response['status'] === 200, 'reserva arquivo ' . ($i + 1) . '/20');
    }
    assertHttp(http("action=begin-file&case=$limitId", ['name' => 'extra.stl', 'size' => 1])['status'] === 422, 'rejeita arquivo 21');
    $uploadToken = '';
    query('INSERT INTO ' . table('admins') . ' (username,display_name,password_hash,must_change_password) VALUES (%s,%s,%s,0)', [$username, 'Administrador Teste', password_hash($password, PASSWORD_DEFAULT)]);
    global $wpdb;
    $adminId = (int) $wpdb->insert_id;
    assertHttp(http('page=login', ['csrf' => $csrf, 'operation' => 'login', 'username' => $username, 'password' => 'incorreta'])['status'] === 401, 'rejeita senha incorreta');
    assertHttp(http('page=login', ['csrf' => $csrf, 'operation' => 'login', 'username' => $username, 'password' => $password])['status'] === 303, 'login administrativo');
    refreshCsrf('page=admin');
    $detail = http("page=admin&case=$case");
    assertHttp($detail['status'] === 200 && str_contains($detail['body'], 'Ainda não baixado'), 'painel apresenta caso e estado de download');
    $download = http("page=download&file=$testFile");
    assertHttp($download['status'] === 200 && hash_equals($bytes, $download['body']), 'download preserva bytes originais');
    $audit = row('SELECT * FROM ' . table('downloads') . ' WHERE file_id = %s', [$testFile]);
    assertHttp($audit && $audit['admin_name'] === 'Administrador Teste' && $audit['transfer_status'] === 'streamed', 'registra usuário e transmissão do download');
    $detail = http("page=admin&case=$case");
    assertHttp(str_contains($detail['body'], 'Último download: Administrador Teste'), 'mostra último usuário que baixou');
    assertHttp(http("page=admin&case=$case", ['csrf' => $csrf, 'operation' => 'status', 'case' => $case, 'status' => 'submitted'])['status'] === 303, 'atualiza situação do caso');
    $newPassword = bin2hex(random_bytes(20));
    assertHttp(http('page=password', ['csrf' => $csrf, 'operation' => 'password', 'current_password' => $password, 'password' => $newPassword, 'confirmation' => $newPassword])['status'] === 303, 'troca senha administrativa');
    refreshCsrf('page=admin');
    assertHttp(http('page=admin', ['csrf' => $csrf, 'operation' => 'logout'])['status'] === 303, 'encerra sessão');
    assertHttp(http("page=download&file=$testFile")['status'] === 401, 'nega download após logout');
    echo "$checks verificações HTTP aprovadas.\n";
} finally {
    foreach ($ids as $id) {
        $files = rows('SELECT id FROM ' . table('files') . ' WHERE case_id = %s', [$id]);
        foreach ($files as $file) {
            query('DELETE FROM ' . table('downloads') . ' WHERE file_id = %s', [$file['id']]);
            $path = privatePath("uploads/$id/" . $file['id']);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Falha ao limpar arquivo do teste.');
            }
        }
        query('DELETE FROM ' . table('files') . ' WHERE case_id = %s', [$id]);
        query('DELETE FROM ' . table('cases') . ' WHERE id = %s', [$id]);
        $lockPath = privatePath("uploads/$id/.lock");
        if (is_file($lockPath)) {
            unlink($lockPath);
        }
        rmdir(privatePath("uploads/$id"));
    }
    if ($adminId) {
        query('DELETE FROM ' . table('admins') . ' WHERE id = %d', [$adminId]);
    }
    if (is_file($cookie)) {
        unlink($cookie);
    }
}
