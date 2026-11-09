<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/DownloadStatus.php';

use function EnvioFacil\downloadStatus;

$pending = ['last_download_at' => null, 'last_download_by' => null];
$downloaded = ['last_download_at' => '2026-11-09 20:00:00', 'last_download_by' => 'Otavio'];
$another = ['last_download_at' => '2026-11-09 20:01:00', 'last_download_by' => 'Doc'];
$tests = [
    [[], false, 0, []],
    [[$pending], false, 0, []],
    [[$downloaded], true, 1, ['Otavio']],
    [[$pending, $downloaded], false, 1, ['Otavio']],
    [[$downloaded, $another], true, 2, ['Otavio', 'Doc']],
    [[$downloaded, $downloaded], true, 2, ['Otavio']],
];
foreach ($tests as $index => [$files, $complete, $count, $names]) {
    $result = downloadStatus($files);
    if ($result['complete'] !== $complete || $result['downloaded'] !== $count
        || $result['names'] !== $names || $result['total'] !== count($files)
        || $result['label'] !== ($complete ? 'Arquivo baixado' : 'Pendente — arquivo não baixado')) {
        throw new RuntimeException('FAIL: indicador de download, cenário ' . $index);
    }
}
echo "6 cenários aprovados: vazio, pendente, baixado, parcial, múltiplos usuários e nomes sem duplicação.\n";
