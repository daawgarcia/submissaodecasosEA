<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use function EnvioFacil\{table, row, rows, query, privatePath};

$protocol = $argv[1] ?? '';
$case = row('SELECT id FROM ' . table('cases') . ' WHERE protocol = %s AND dentist = %s AND patient = %s', [$protocol, 'Dentista Validação Interface', 'Paciente Fictício Interface']);
if (!$case) {
    throw new RuntimeException('Caso sintético de interface não encontrado. Nenhum dado foi excluído.');
}
$files = rows('SELECT id FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
foreach ($files as $file) {
    query('DELETE FROM ' . table('downloads') . ' WHERE file_id = %s', [$file['id']]);
    $path = privatePath('uploads/' . $case['id'] . '/' . $file['id']);
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Não foi possível excluir o arquivo sintético.');
    }
}
query('DELETE FROM ' . table('files') . ' WHERE case_id = %s', [$case['id']]);
query('DELETE FROM ' . table('cases') . ' WHERE id = %s', [$case['id']]);
$dir = privatePath('uploads/' . $case['id']);
if (is_file("$dir/.lock") && !unlink("$dir/.lock")) {
    throw new RuntimeException('Não foi possível remover o bloqueio sintético.');
}
if (!rmdir($dir)) {
    throw new RuntimeException('Não foi possível remover a pasta sintética.');
}
echo "Caso sintético removido: $protocol\n";
