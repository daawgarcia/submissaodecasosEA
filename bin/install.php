<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$wpPath = $argv[1] ?? '';
$privateDir = $argv[2] ?? '';
if (!is_file($wpPath) || $privateDir === '' || !str_starts_with($privateDir, '/')) {
    throw new RuntimeException('Uso: php bin/install.php /caminho/wp-load.php /caminho/privado');
}
umask(0077);
foreach ([$privateDir, "$privateDir/uploads", "$privateDir/sessions"] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('Não foi possível criar o diretório privado.');
    }
}
if (!is_file("$root/config.php")) {
    $config = [
        'wordpress' => $wpPath,
        'private_dir' => $privateDir,
        'public_url' => 'https://enviofacil.estheticaligner.com.br',
        'notification_email' => 'tamires@estheticaligner.com.br',
        'app_key' => bin2hex(random_bytes(32)),
    ];
    if (file_put_contents("$root/config.php", "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar a configuração.');
    }
}
require "$root/src/bootstrap.php";
global $wpdb;
$collate = $wpdb->get_charset_collate();
$schemas = [
    'cases' => "id char(32) NOT NULL,
        protocol varchar(32) NOT NULL,
        dentist varchar(160) NOT NULL,
        whatsapp varchar(15) NOT NULL,
        patient varchar(160) NOT NULL,
        upload_hash char(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'draft',
        mail_status varchar(20) NOT NULL DEFAULT 'pending',
        mail_attempts int unsigned NOT NULL DEFAULT 0,
        mail_attempted_at datetime NULL,
        created_at datetime NOT NULL,
        consent_at datetime NOT NULL,
        submitted_at datetime NULL,
        PRIMARY KEY (id),
        UNIQUE KEY protocol (protocol),
        KEY dentist_phone (whatsapp),
        KEY status_date (status,submitted_at)",
    'files' => "id char(32) NOT NULL,
        case_id char(32) NOT NULL,
        name varchar(220) NOT NULL,
        size bigint unsigned NOT NULL,
        verified tinyint unsigned NOT NULL DEFAULT 0,
        sha256 char(64) NULL,
        last_download_by varchar(100) NULL,
        last_download_at datetime NULL,
        PRIMARY KEY (id),
        KEY case_id (case_id)",
    'admins' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
        username varchar(60) NOT NULL,
        display_name varchar(100) NOT NULL,
        password_hash varchar(255) NOT NULL,
        must_change_password tinyint unsigned NOT NULL DEFAULT 0,
        session_version int unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY username (username)",
    'downloads' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
        file_id char(32) NOT NULL,
        admin_id bigint unsigned NOT NULL,
        admin_name varchar(100) NOT NULL,
        started_at datetime NOT NULL,
        transfer_status varchar(20) NOT NULL DEFAULT 'started',
        PRIMARY KEY (id),
        KEY file_id (file_id)",
    'limits' => "id char(64) NOT NULL,
        hits int unsigned NOT NULL DEFAULT 0,
        expires_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY expires_at (expires_at)",
];
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
foreach ($schemas as $name => $columns) {
    $table = EnvioFacil\table($name);
    dbDelta("CREATE TABLE $table (\n$columns\n) ENGINE=InnoDB $collate;");
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Falha ao criar tabela ' . $name . ': ' . $wpdb->last_error);
    }
    if (!EnvioFacil\row('SHOW TABLES LIKE %s', [$wpdb->esc_like($table)])) {
        throw new RuntimeException('Tabela não criada: ' . $name);
    }
}
if (!EnvioFacil\row('SELECT id FROM ' . EnvioFacil\table('admins') . ' LIMIT 1')) {
    $code = EnvioFacil\token();
    file_put_contents("$privateDir/setup.json", json_encode(['hash' => EnvioFacil\digest($code), 'expires' => time() + 86400], JSON_THROW_ON_ERROR), LOCK_EX);
    file_put_contents("$privateDir/activation-code.txt", $code . PHP_EOL, LOCK_EX);
    echo "Ativação preparada; código salvo na pasta privada, válido por 24 horas.\n";
}
echo "Instalação concluída. Cinco tabelas próprias no banco existente; tabelas do site preservadas.\n";
