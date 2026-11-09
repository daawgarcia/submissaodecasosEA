<?php
declare(strict_types=1);

namespace EnvioFacil;

const MAX_CASE_BYTES = 500 * 1024 * 1024;
const MAX_FILES = 20;
const CHUNK_BYTES = 2 * 1024 * 1024;

function fullName(string $value, string $label): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) > 160 || !preg_match('/^\p{L}[\p{L}\p{M}\'’.-]*(?: [\p{L}\p{M}\'’.-]+)+$/u', $value)) {
        throw new \InvalidArgumentException("Informe o nome completo {$label}.");
    }
    return $value;
}

function whatsapp(string $value): string
{
    if (!preg_match('/^[+\d\s().-]+$/', $value)) {
        throw new \InvalidArgumentException('Informe um WhatsApp válido, com DDD e código do país.');
    }
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) < 10 || strlen($digits) > 15 || preg_match('/^(\d)\1+$/', $digits)) {
        throw new \InvalidArgumentException('Informe um WhatsApp válido, com DDD e código do país.');
    }
    return $digits;
}

function fileName(string $name): string
{
    if ($name === '' || strlen($name) > 220 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)) {
        throw new \InvalidArgumentException('Nome de arquivo inválido.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['stl', 'jpg', 'jpeg', 'png', 'zip'], true)) {
        throw new \InvalidArgumentException('Envie somente arquivos STL, JPG, PNG ou ZIP.');
    }
    return $name;
}

function validateFile(string $path, string $name): void
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        $image = @getimagesize($path);
        $expected = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($image === false || $image[2] !== $expected) {
            throw new \InvalidArgumentException('A imagem não corresponde ao formato informado.');
        }
    } elseif ($extension === 'zip') {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new \InvalidArgumentException('O ZIP está inválido ou corrompido.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
                throw new \InvalidArgumentException('O ZIP deve conter entre 1 e 10.000 itens.');
            }
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    throw new \InvalidArgumentException('Não foi possível verificar o ZIP.');
                }
                $entryName = str_replace('\\', '/', $entry['name']);
                $expanded += $entry['size'];
                if (str_starts_with($entryName, '/') || preg_match('~(^|/)\.\.(/|$)|^[a-z]:~i', $entryName)
                    || preg_match('/\.(php\d*|phtml|phar|exe|bat|cmd|js|html?|sh|ps1|svg|scr|com|msi)(\.|$)/i', $entryName)
                    || !empty($entry['encryption_method']) || $expanded > 2 * 1024 * 1024 * 1024) {
                    throw new \InvalidArgumentException('O ZIP contém itens não permitidos, criptografados ou excede 2 GB descompactado.');
                }
            }
        } finally {
            $zip->close();
        }
    } else {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível verificar o STL.');
        }
        try {
            $header = fread($handle, 84);
            $binary = false;
            if (strlen($header) === 84) {
                $count = unpack('Vcount', substr($header, 80, 4))['count'];
                $binary = $count > 0 && 84 + 50 * $count === $size;
            }
            if (!$binary) {
                rewind($handle);
                $start = fread($handle, 4096);
                fseek($handle, max(0, $size - 4096));
                $end = stream_get_contents($handle);
                if (!preg_match('/^\s*solid\b/i', $start) || !preg_match('/facet\s+normal/i', $start)
                    || !preg_match('/endsolid\b/i', $end) || str_contains($start . $end, "\0")) {
                    throw new \InvalidArgumentException('O arquivo não é um STL reconhecido.');
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
