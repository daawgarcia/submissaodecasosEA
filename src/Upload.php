<?php
declare(strict_types=1);

namespace EnvioFacil;

function publicCase(bool $allowSubmitted = false): array
{
    $id = $_GET['case'] ?? '';
    $secret = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? '';
    $case = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s', [$id]);
    if (!$case || !hash_equals($case['upload_hash'], digest($secret))) {
        throw new HttpError(403, 'Autorização de envio inválida. Inicie um novo envio.');
    }
    if ($case['status'] !== 'draft' && !$allowSubmitted) {
        throw new HttpError(409, 'Este caso já foi enviado.');
    }
    if (strtotime($case['created_at'] . ' UTC') < time() - 86400) {
        throw new HttpError(410, 'O prazo deste envio expirou. Inicie um novo envio.');
    }
    return $case;
}

function withCaseLock(array $case, callable $callback, bool $allowSubmitted = false): mixed
{
    $lock = fopen(privatePath('uploads/' . $case['id'] . '/.lock'), 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new \RuntimeException('Não foi possível reservar o envio.');
    }
    try {
        $fresh = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s', [$case['id']]);
        if (!$fresh || ($fresh['status'] !== 'draft' && !$allowSubmitted)) {
            throw new HttpError(409, 'Este caso já foi enviado.');
        }
        return $callback($fresh);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function createCase(array $data): array
{
    if (!empty($data['website'])) {
        throw new HttpError(400, 'Não foi possível validar o formulário.');
    }
    $dentist = fullName((string) ($data['dentist'] ?? ''), 'do dentista');
    $patient = fullName((string) ($data['patient'] ?? ''), 'do paciente');
    $phone = whatsapp((string) ($data['whatsapp'] ?? ''));
    if (($data['consent'] ?? false) !== true) {
        throw new HttpError(422, 'Confirme que está autorizado a compartilhar os dados do paciente.');
    }
    rateLimit('create', 8);
    $id = bin2hex(random_bytes(16));
    $secret = token();
    $protocol = 'EA-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $dir = privatePath('uploads/' . $id);
    if (!mkdir($dir, 0700)) {
        throw new \RuntimeException('Não foi possível preparar o armazenamento.');
    }
    try {
        query('INSERT INTO ' . table('cases') . ' (id, protocol, dentist, whatsapp, patient, upload_hash, status, mail_status, created_at, consent_at) VALUES (%s,%s,%s,%s,%s,%s,\'draft\',\'pending\',UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$id, $protocol, $dentist, $phone, $patient, digest($secret)]);
    } catch (\Throwable $error) {
        rmdir($dir);
        throw $error;
    }
    return ['id' => $id, 'token' => $secret, 'protocol' => $protocol];
}

function beginFile(array $case, array $data): array
{
    return withCaseLock($case, function (array $case) use ($data): array {
        $name = fileName((string) ($data['name'] ?? ''));
        $size = $data['size'] ?? 0;
        if (!is_int($size) || $size < 1 || $size > MAX_CASE_BYTES) {
            throw new HttpError(422, 'O arquivo deve ter entre 1 byte e 500 MB.');
        }
        $files = rows('SELECT size FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
        if (count($files) >= MAX_FILES || array_sum(array_column($files, 'size')) + $size > MAX_CASE_BYTES) {
            throw new HttpError(422, 'O caso permite até 20 arquivos e 500 MB no total.');
        }
        if (disk_free_space(config('private_dir')) < $size + 1024 * 1024 * 1024) {
            throw new HttpError(503, 'Armazenamento temporariamente indisponível. Entre em contato com a equipe.');
        }
        $id = bin2hex(random_bytes(16));
        query('INSERT INTO ' . table('files') . ' (id, case_id, name, size, verified) VALUES (%s,%s,%s,%d,0)', [$id, $case['id'], $name, $size]);
        return ['id' => $id];
    });
}

function uploadChunk(array $case): array
{
    return withCaseLock($case, function (array $case): array {
        $file = row('SELECT * FROM ' . table('files') . ' WHERE id = %s AND case_id = %s', [$_GET['file'] ?? '', $case['id']]);
        $offset = filter_var($_GET['offset'] ?? '', FILTER_VALIDATE_INT);
        if (!$file || $file['verified'] || $offset === false || $offset < 0) {
            throw new HttpError(422, 'Arquivo ou posição de envio inválida.');
        }
        $input = fopen('php://input', 'rb');
        $bytes = stream_get_contents($input, CHUNK_BYTES + 1);
        fclose($input);
        $length = strlen($bytes);
        if ($length < 1 || $length > CHUNK_BYTES || $offset + $length > (int) $file['size']) {
            throw new HttpError(422, 'Bloco de arquivo fora do limite permitido.');
        }
        $path = privatePath('uploads/' . $case['id'] . '/' . $file['id']);
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível gravar o arquivo.');
        }
        try {
            $current = fstat($handle)['size'];
            if ($offset < $current && $offset + $length <= $current) {
                fseek($handle, $offset);
                if (!hash_equals(hash('sha256', $bytes), hash('sha256', fread($handle, $length)))) {
                    throw new HttpError(409, 'Bloco repetido com conteúdo diferente.');
                }
            } elseif ($offset === $current) {
                fseek($handle, $offset);
                if (fwrite($handle, $bytes) !== $length || !fflush($handle)) {
                    ftruncate($handle, $offset);
                    throw new \RuntimeException('A gravação foi interrompida. Tente novamente.');
                }
                $current += $length;
            } else {
                throw new HttpError(409, 'Posição de envio divergente. Reinicie o envio.');
            }
            return ['received' => $current];
        } finally {
            fclose($handle);
        }
    });
}

function completeFile(array $case, array $data): array
{
    return withCaseLock($case, function (array $case) use ($data): array {
        $file = row('SELECT * FROM ' . table('files') . ' WHERE id = %s AND case_id = %s', [$data['file'] ?? '', $case['id']]);
        if (!$file) {
            throw new HttpError(404, 'Arquivo não encontrado.');
        }
        $path = privatePath('uploads/' . $case['id'] . '/' . $file['id']);
        if (!is_file($path) || filesize($path) !== (int) $file['size']) {
            throw new HttpError(422, 'O arquivo ainda não foi recebido por completo.');
        }
        validateFile($path, $file['name']);
        $hash = hash_file('sha256', $path);
        query('UPDATE ' . table('files') . ' SET verified = 1, sha256 = %s WHERE id = %s', [$hash, $file['id']]);
        return ['verified' => true];
    });
}

function submitCase(array $case): array
{
    $submitted = withCaseLock($case, function (array $case): array {
        if ($case['status'] !== 'draft') {
            return $case;
        }
        $files = rows('SELECT verified FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
        if (!$files || array_filter($files, static fn(array $file): bool => (int) $file['verified'] !== 1)) {
            throw new HttpError(422, 'Envie e valide todos os arquivos antes de concluir.');
        }
        query('UPDATE ' . table('cases') . ' SET status = \'received\', submitted_at = UTC_TIMESTAMP() WHERE id = %s', [$case['id']]);
        $case['status'] = 'received';
        return $case;
    }, true);
    $sent = $submitted['mail_status'] === 'sent' || notify($submitted);
    return ['protocol' => $case['protocol'], 'notification' => $sent ? 'sent' : 'pending'];
}
