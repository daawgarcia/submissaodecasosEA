<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Upload.php';

use function EnvioFacil\{table, rows, row, query, privatePath, notify, withCaseLock};

$lock = fopen(privatePath('maintenance.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Manutenção já em andamento ou bloqueio indisponível.');
}
try {
    $statePath = privatePath('maintenance-state.json');
    if (file_put_contents($statePath, json_encode(['started_at' => gmdate('c'), 'status' => 'running'], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível registrar a execução da manutenção.');
    }
    $cases = rows('SELECT * FROM ' . table('cases') . " WHERE status <> 'draft' AND mail_attempts < 10 AND (mail_status IN ('pending','failed') OR (mail_status = 'sending' AND mail_attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))) ORDER BY submitted_at LIMIT 20");
    foreach ($cases as $case) {
        echo $case['protocol'] . ': ' . (notify($case) ? 'notificação aceita' : 'notificação pendente/falhou') . PHP_EOL;
    }
    $drafts = rows('SELECT * FROM ' . table('cases') . " WHERE status = 'draft' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) LIMIT 100");
    foreach ($drafts as $draft) {
        withCaseLock($draft, function (array $case): void {
            $files = rows('SELECT id FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
            foreach ($files as $file) {
                $path = privatePath('uploads/' . $case['id'] . '/' . $file['id']);
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Não foi possível remover arquivo de envio abandonado.');
                }
            }
            query('DELETE FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
            query('DELETE FROM ' . table('cases') . ' WHERE id = %s AND status = \'draft\'', [$case['id']]);
        });
        $dir = privatePath('uploads/' . $draft['id']);
        if (!unlink("$dir/.lock") || !rmdir($dir)) {
            throw new RuntimeException('Não foi possível remover pasta de envio abandonado.');
        }
    }
    query('DELETE FROM ' . table('limits') . ' WHERE expires_at < UTC_TIMESTAMP()');
    $activation = privatePath('activation-code.txt');
    if (is_file($activation) && !is_file(privatePath('setup.json')) && !unlink($activation)) {
        throw new RuntimeException('Não foi possível remover código de ativação utilizado.');
    }
    if (file_put_contents($statePath, json_encode(['completed_at' => gmdate('c'), 'status' => 'completed'], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível registrar a conclusão da manutenção.');
    }
    echo "Manutenção concluída.\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
