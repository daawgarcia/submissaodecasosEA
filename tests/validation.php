<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Validation.php';

use function EnvioFacil\{fullName, whatsapp, fileName, validateFile};

$checks = 0;
function check(bool $value, string $label): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}
function rejects(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $exception) {
        check(true, $label);
        return;
    }
    check(false, $label);
}
check(fullName('  Maria  da Silva ', 'do dentista') === 'Maria da Silva', 'normaliza nome completo');
check(fullName('João D’Ávila', 'do paciente') === 'João D’Ávila', 'aceita nomes acentuados');
rejects(fn() => fullName('Maria', 'do dentista'), 'rejeita nome incompleto');
rejects(fn() => fullName('<script> Teste', 'do paciente'), 'rejeita HTML');
check(whatsapp('+55 (11) 99999-1234') === '5511999991234', 'normaliza telefone');
rejects(fn() => whatsapp('abc11999991234'), 'rejeita telefone com letras');
rejects(fn() => whatsapp('11111111111'), 'rejeita telefone repetido');
check(fileName('Arcada superior.STL') === 'Arcada superior.STL', 'aceita STL');
rejects(fn() => fileName('../caso.stl'), 'rejeita caminho relativo');
rejects(fn() => fileName('caso.php'), 'rejeita script');
rejects(fn() => fileName("caso\r\n.png"), 'rejeita quebra de linha');
$dir = sys_get_temp_dir() . '/enviofacil-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $path = "$dir/test";
    file_put_contents($path, str_repeat("\0", 80) . pack('V', 1) . str_repeat("\0", 50));
    validateFile($path, 'caso.stl');
    check(true, 'valida STL binário');
    file_put_contents($path, str_repeat("\0", 80) . pack('V', 3) . str_repeat("\0", 50));
    rejects(fn() => validateFile($path, 'caso.stl'), 'rejeita STL truncado');
    file_put_contents($path, "solid test\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 1 0 0\nvertex 0 1 0\nendloop\nendfacet\nendsolid test\n");
    validateFile($path, 'caso.stl');
    check(true, 'valida STL ASCII');
    file_put_contents($path, '<?php echo "not an image";');
    rejects(fn() => validateFile($path, 'imagem.png'), 'rejeita imagem falsa');
    unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('arcada.stl', 'test');
    $zip->close();
    validateFile($path, 'caso.zip');
    check(true, 'valida ZIP');
    unlink($path);
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('../arcada.stl', 'test');
    $zip->close();
    rejects(fn() => validateFile($path, 'caso.zip'), 'rejeita ZIP com traversal');
    unlink($path);
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('malware.php', '<?php');
    $zip->close();
    rejects(fn() => validateFile($path, 'caso.zip'), 'rejeita ZIP com executável');
} finally {
    if (is_file("$dir/test")) {
        unlink("$dir/test");
    }
    rmdir($dir);
}
echo "$checks verificações aprovadas.\n";
