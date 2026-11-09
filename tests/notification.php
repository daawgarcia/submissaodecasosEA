<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use function EnvioFacil\{table, query, row, notify, digest};

set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
});

$id = bin2hex(random_bytes(16));
$protocol = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
$captured = [];
$intercept = static function ($result, array $mail) use (&$captured): bool {
    $captured[] = $mail;
    return true;
};
add_filter('pre_wp_mail', $intercept, PHP_INT_MAX, 2);

try {
    query('INSERT INTO ' . table('cases') . ' (id,protocol,dentist,whatsapp,patient,upload_hash,status,mail_status,created_at,consent_at,submitted_at) VALUES (%s,%s,%s,%s,%s,%s,\'received\',\'pending\',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())',
        [$id, $protocol, 'Dentista Teste Notificação', '5511999991234', 'Paciente Fictício Notificação', digest($id)]);
    $case = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s', [$id]);
    if (!notify($case) || count($captured) !== 1) {
        throw new RuntimeException('FAIL: notificação não foi processada.');
    }
    $mail = $captured[0];
    if ($mail['to'] !== 'tamires@estheticaligner.com.br'
        || !in_array('Cc: doc@estheticaligner.com.br', $mail['headers'], true)
        || !in_array('Cc: marketing@estheticaligner.com.br', $mail['headers'], true)
        || !str_contains($mail['subject'], $protocol)
        || str_contains($mail['message'], $case['patient'])
        || !empty($mail['attachments'])) {
        throw new RuntimeException('FAIL: destinatários ou conteúdo da notificação incorretos.');
    }
    if (notify($case) || count($captured) !== 1) {
        throw new RuntimeException('FAIL: notificação duplicada.');
    }
    echo "OK: Tamires como destinatária, doc e marketing em Cc, protocolo sem dados do paciente, sem anexos e sem duplicação.\n";
    echo "Transporte interceptado: nenhum e-mail real enviado neste teste.\n";
} finally {
    remove_filter('pre_wp_mail', $intercept, PHP_INT_MAX);
    query('DELETE FROM ' . table('cases') . ' WHERE id = %s AND protocol = %s', [$id, $protocol]);
}
