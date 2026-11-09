<?php
declare(strict_types=1);

namespace EnvioFacil;

function downloadStatus(array $files): array
{
    $downloaded = array_filter($files, static fn(array $file): bool => !empty($file['last_download_at']));
    $complete = count($files) > 0 && count($downloaded) === count($files);
    $names = array_values(array_unique(array_filter(array_column($downloaded, 'last_download_by'))));
    return [
        'complete' => $complete,
        'label' => $complete ? 'Arquivo baixado' : 'Pendente — arquivo não baixado',
        'downloaded' => count($downloaded),
        'total' => count($files),
        'names' => $names,
    ];
}
