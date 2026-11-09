<?php
declare(strict_types=1);

use function EnvioFacil\{e, table, rows, row};

$statuses = ['received' => 'Recebido', 'processing' => 'Em análise', 'submitted' => 'Enviado ao Ortholab'];
function sizeLabel(int $bytes): string
{
    return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>">
    <title>Envio Fácil | Esthetic Aligner</title>
    <link rel="icon" href="assets/logo-esthetic-aligner.png" type="image/png">
    <link rel="stylesheet" href="assets/app.css?v=<?= e(filemtime(dirname(__DIR__) . '/public/assets/app.css')) ?>">
    <script src="assets/app.js?v=<?= e(filemtime(dirname(__DIR__) . '/public/assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="header">
    <div class="header-inner">
    <a class="brand" href="?" aria-label="Envio Fácil — Esthetic Aligner"><img src="assets/logo-esthetic-aligner.png" alt="Esthetic Aligner" width="300" height="65"></a>
    <nav aria-label="Navegação principal">
        <?php if ($page === 'admin' || $page === 'password'): ?>
            <?php if ($page === 'admin'): ?><a href="?page=password">Alterar senha</a><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="logout"><button class="text-button">Sair do painel</button></form>
        <?php else: ?>
            <a class="main-site-link" href="https://estheticaligner.com.br/" target="_blank" rel="noopener">Site Esthetic Aligner</a>
            <a class="admin-link" href="?page=login">Área administrativa</a>
        <?php endif; ?>
    </nav>
    </div>
</header>
<main>
<nav class="breadcrumb" aria-label="Você está aqui"><a href="https://estheticaligner.com.br/" target="_blank" rel="noopener">Esthetic Aligner</a><span aria-hidden="true">›</span><a href="?">Envio Fácil</a><?php if ($page !== 'upload'): ?><span aria-hidden="true">›</span><span aria-current="page"><?= e(match ($page) { 'admin' => 'Painel administrativo', 'login' => 'Acesso da equipe', 'setup' => 'Ativação', 'password' => 'Alterar senha', 'privacy' => 'Privacidade' }) ?></span><?php endif; ?></nav>
<?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="notice" role="status"><?= e($flash) ?></div><?php endif; ?>

<?php if ($page === 'upload'): ?>
    <section class="intro">
        <div><p class="eyebrow">MENOS ETAPAS. MAIS SORRISOS.</p><h1>Seu próximo caso<br>começa aqui.</h1><p class="lead">Envie os arquivos do seu paciente para a nossa equipe.<br>Simples, organizado e sem precisar de cadastro.</p></div>
    </section>
    <div class="upload-layout">
        <form id="upload-form" class="card" autocomplete="off">
            <div class="section-title"><span class="step">01</span><div><h2>Identifique o caso</h2><p>Todos os campos abaixo são obrigatórios.</p></div></div>
            <div class="fields">
                <label class="wide">Nome Completo do Credenciado <span class="required">*</span><input name="dentist" required maxlength="160" placeholder="Como podemos chamar você?" autocomplete="name"></label>
                <label>WhatsApp com DDD <span class="required">*</span><input name="whatsapp" type="tel" required maxlength="25" placeholder="+55 (11) 99999-9999" autocomplete="tel"><small>Inclua o código do país se estiver fora do Brasil.</small></label>
                <label>Nome completo do paciente <span class="required">*</span><input name="patient" required maxlength="160" placeholder="Nome e sobrenome"></label>
                <label class="honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <div class="divider"></div>
            <div class="section-title"><span class="step">02</span><div><h2>Adicione os arquivos</h2><p>STL, imagens JPG/PNG ou um arquivo ZIP.</p></div></div>
            <div id="drop-zone" class="drop-zone">
                <span class="upload-icon" aria-hidden="true">↑</span>
                <strong>Arraste seus arquivos até aqui</strong><span>ou escolha os arquivos no seu dispositivo</span>
                <label class="file-button">Selecionar arquivos<input id="files" type="file" multiple accept=".stl,.jpg,.jpeg,.png,.zip"></label>
                <small>Até 20 arquivos · 500 MB no total</small>
            </div>
            <ul id="file-list" class="file-list" aria-label="Arquivos selecionados"></ul>
            <p id="file-summary" class="muted"></p>
            <label class="consent"><input name="consent" type="checkbox" required><span>Confirmo que estou autorizado a compartilhar os dados e arquivos deste paciente para avaliação e planejamento do caso. Li o <a href="?page=privacy" target="_blank" rel="noopener">aviso de privacidade</a>.</span></label>
            <div id="upload-progress" class="upload-progress" hidden><label for="progress-bar" id="progress-label">Preparando envio…</label><progress id="progress-bar" max="100" value="0"></progress></div>
            <div id="upload-message" role="status" aria-live="polite"></div>
            <button type="submit" class="primary submit" id="submit-button">Enviar caso <span aria-hidden="true">↗</span></button>
            <p class="secure-caption">Seus arquivos não ficam disponíveis publicamente.</p>
            <noscript><p class="notice error">Ative o JavaScript para enviar os arquivos com segurança.</p></noscript>
        </form>
        <aside class="sidebar">
            <div class="card tips"><p class="eyebrow">ANTES DE ENVIAR</p><h2>Tudo pronto?</h2><ol><li><strong>Confira a identificação</strong><p>Use os nomes completos para facilitar a organização do caso.</p></li><li><strong>Reúna os arquivos</strong><p>Inclua os escaneamentos STL e as imagens necessárias. Se preferir, envie em ZIP.</p></li><li><strong>Guarde seu protocolo</strong><p>Ao concluir, você receberá um número para acompanhar o caso com a equipe.</p></li></ol></div>
            <div class="help-note"><strong>O que acontece depois?</strong><p>Nossa equipe recebe uma notificação, confere os arquivos e realiza a submissão manual no Ortholab.</p></div>
        </aside>
    </div>
    <section id="success" class="card success" hidden tabindex="-1"><span class="success-icon" aria-hidden="true">✓</span><p class="eyebrow">ARQUIVOS RECEBIDOS</p><h2>Pronto. Seu caso está com a gente.</h2><p>Guarde este protocolo para falar com nossa equipe:</p><strong id="protocol"></strong><p id="notification-note"></p><a class="primary" href="?">Enviar outro caso</a></section>
<?php elseif ($page === 'login' || $page === 'setup'): ?>
    <section class="card auth-card">
        <p class="eyebrow">ACESSO RESTRITO</p><h1><?= $page === 'setup' ? 'Ativar o painel' : 'Olá, equipe EA.' ?></h1>
        <p class="muted"><?= $page === 'setup' ? 'Use o código de ativação fornecido na instalação e crie seu acesso.' : 'Entre para organizar os casos e baixar os arquivos.' ?></p>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="operation" value="<?= $page === 'setup' ? 'setup' : 'login' ?>">
            <?php if ($page === 'setup'): ?><label>Código de ativação<input name="setup_token" required autocomplete="off"></label><?php endif; ?>
            <label>Usuário<input name="username" maxlength="60" required autocomplete="username" autocapitalize="none" spellcheck="false"></label>
            <label>Senha<input type="password" name="password" required <?= $page === 'setup' ? 'minlength="14" maxlength="72" autocomplete="new-password"' : 'autocomplete="current-password"' ?>></label>
            <?php if ($page === 'setup'): ?><small>Use uma senha exclusiva de 14 a 72 caracteres.</small><?php endif; ?>
            <button class="primary"><?= $page === 'setup' ? 'Criar acesso administrativo' : 'Entrar no painel' ?></button>
        </form>
    </section>
<?php elseif ($page === 'password'): ?>
    <section class="card auth-card"><p class="eyebrow">PROTEJA SEU ACESSO</p><h1>Defina sua senha.</h1><p class="muted"><?= (int) $admin['must_change_password'] === 1 ? 'Antes de acessar os casos, substitua a senha inicial por uma senha exclusiva.' : 'Atualize sua senha de acesso ao painel.' ?></p><form method="post" class="auth-form"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="password"><label>Senha atual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nova senha<input type="password" name="password" minlength="14" maxlength="72" required autocomplete="new-password"></label><label>Repita a nova senha<input type="password" name="confirmation" minlength="14" maxlength="72" required autocomplete="new-password"></label><small>Use de 14 a 72 caracteres, sem seu nome de usuário.</small><button class="primary">Salvar nova senha</button></form></section>
<?php elseif ($page === 'admin'): ?>
    <div class="admin-heading"><div><p class="eyebrow">ÁREA ADMINISTRATIVA</p><h1>Central de casos</h1></div><span class="muted"><?= e($admin['display_name']) ?></span></div>
    <?php
    $caseId = (string) ($_GET['case'] ?? '');
    $search = mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 160);
    $phone = (string) ($_GET['dentist'] ?? '');
    $currentPage = max(1, (int) ($_GET['p'] ?? 1));
    if ($caseId):
        $case = row('SELECT * FROM ' . table('cases') . ' WHERE id = %s AND status <> \'draft\'', [$caseId]);
        if (!$case) { throw new EnvioFacil\HttpError(404, 'Caso não encontrado.'); }
        $files = rows('SELECT * FROM ' . table('files') . ' WHERE case_id = %s ORDER BY name', [$caseId]);
    ?>
        <a class="back-link" href="?page=admin">← Todos os dentistas</a>
        <section class="card case-detail">
            <div class="detail-heading"><h2><?= e($case['protocol']) ?></h2><span class="badge"><?= e($statuses[$case['status']]) ?></span></div>
            <dl class="case-data"><div><dt>Dentista</dt><dd><?= e($case['dentist']) ?></dd></div><div><dt>WhatsApp</dt><dd><a href="https://wa.me/<?= e($case['whatsapp']) ?>" target="_blank" rel="noopener noreferrer"><?= e($case['whatsapp']) ?></a></dd></div><div><dt>Paciente</dt><dd><?= e($case['patient']) ?></dd></div><div><dt>Recebido em (Brasília)</dt><dd><?= e((new DateTimeImmutable($case['submitted_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i')) ?></dd></div></dl>
            <h3>Arquivos do caso</h3>
            <ul class="download-list"><?php foreach ($files as $file): ?><li><div><strong><?= e($file['name']) ?></strong><small><?= e(sizeLabel((int) $file['size'])) ?></small><small><?php if ($file['last_download_at']): ?>Último download: <?= e($file['last_download_by']) ?> · <?= e((new DateTimeImmutable($file['last_download_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s')) ?> (Brasília)<?php else: ?>Ainda não baixado<?php endif; ?></small></div><a class="secondary" href="?page=download&file=<?= e($file['id']) ?>">Baixar arquivo ↓</a></li><?php endforeach; ?></ul>
            <p class="muted">O registro indica o início do download pelo usuário autenticado. Não confirma que o arquivo foi salvo no dispositivo.</p>
            <details><summary>Histórico de downloads</summary>
                <?php $downloads = rows('SELECT d.*, f.name FROM ' . table('downloads') . ' d JOIN ' . table('files') . ' f ON f.id = d.file_id WHERE f.case_id = %s ORDER BY d.id DESC LIMIT 100', [$caseId]); ?>
                <?php if (!$downloads): ?><p>Nenhum download registrado.</p><?php endif; ?>
                <ul class="audit-list"><?php foreach ($downloads as $download): ?><li><strong><?= e($download['admin_name']) ?></strong> · <?= e($download['name']) ?> · <?= e((new DateTimeImmutable($download['started_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s')) ?> (Brasília) · <?= e(['started' => 'Iniciado', 'streamed' => 'Transmitido pelo servidor', 'interrupted' => 'Interrompido'][$download['transfer_status']]) ?></li><?php endforeach; ?></ul><p class="muted">Últimos 100 registros deste caso.</p>
            </details>
            <div class="admin-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="status"><input type="hidden" name="case" value="<?= e($caseId) ?>"><label>Situação<select name="status"><?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>" <?= $case['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="primary">Salvar situação</button></form>
                <div><p><strong>Notificação por e-mail:</strong> <?= e(['pending' => 'Pendente', 'sending' => 'Em envio', 'sent' => 'Aceita pelo serviço de e-mail', 'failed' => 'Falhou — requer atenção'][$case['mail_status']]) ?></p>
                <?php if ($case['mail_status'] !== 'sent'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="retry-mail"><input type="hidden" name="case" value="<?= e($caseId) ?>"><button class="secondary">Tentar notificação novamente</button></form><?php endif; ?></div>
            </div>
        </section>
    <?php else:
        global $wpdb;
        $where = "status <> 'draft'";
        $args = [];
        if ($search !== '') {
            $where .= ' AND (dentist LIKE %s OR patient LIKE %s OR protocol LIKE %s OR whatsapp LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $args = [$like, $like, $like, $like];
        }
        if ($phone !== '') {
            $where .= ' AND whatsapp = %s';
            $args[] = $phone;
        }
        $counts = row('SELECT COUNT(*) AS total, SUM(mail_status IN (\'failed\',\'pending\')) AS pending FROM ' . table('cases') . ' WHERE ' . $where, $args);
        $dentists = rows('SELECT whatsapp, MAX(dentist) AS dentist, COUNT(*) AS total FROM ' . table('cases') . " WHERE status <> 'draft' GROUP BY whatsapp ORDER BY dentist LIMIT 200");
        $cases = rows('SELECT * FROM ' . table('cases') . " WHERE $where ORDER BY submitted_at DESC LIMIT 25 OFFSET %d", [...$args, ($currentPage - 1) * 25]);
    ?>
        <div class="stats"><div class="card"><strong><?= e($counts['total']) ?></strong><span>casos encontrados</span></div><div class="card"><strong><?= e($counts['pending'] ?? 0) ?></strong><span>notificações pendentes ou com falha</span></div></div>
        <div class="admin-layout">
            <aside class="card dentist-list"><h2>Por dentista</h2><p class="muted">Agrupados pelo WhatsApp informado. Os dados são autodeclarados.</p><a class="<?= $phone === '' ? 'active' : '' ?>" href="?page=admin">Todos os dentistas</a><?php foreach ($dentists as $dentist): ?><a class="<?= $phone === $dentist['whatsapp'] ? 'active' : '' ?>" href="?page=admin&dentist=<?= e($dentist['whatsapp']) ?>"><strong><?= e($dentist['dentist']) ?></strong><small><?= e($dentist['whatsapp']) ?> · <?= e($dentist['total']) ?> caso(s)</small></a><?php endforeach; ?></aside>
            <section class="card cases-list">
                <form method="get" class="search"><input type="hidden" name="page" value="admin"><input type="hidden" name="dentist" value="<?= e($phone) ?>"><label class="sr-only" for="search">Buscar casos</label><input id="search" name="search" value="<?= e($search) ?>" placeholder="Buscar por dentista, paciente ou protocolo"><button class="secondary">Buscar</button></form>
                <?php if (!$cases): ?><p class="empty">Nenhum caso encontrado.</p><?php endif; ?>
                <?php foreach ($cases as $item): ?><a class="case-row" href="?page=admin&case=<?= e($item['id']) ?>"><div><span class="eyebrow"><?= e($item['protocol']) ?></span><h3><?= e($item['patient']) ?></h3><p><?= e($item['dentist']) ?></p><small><?= e((new DateTimeImmutable($item['submitted_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i')) ?></small></div><span class="badge"><?= e($statuses[$item['status']]) ?></span></a><?php endforeach; ?>
                <nav class="pagination" aria-label="Paginação"><?php if ($currentPage > 1): ?><a href="?<?= e(http_build_query(['page' => 'admin', 'search' => $search, 'dentist' => $phone, 'p' => $currentPage - 1])) ?>">← Anterior</a><?php endif; ?><span>Página <?= e($currentPage) ?></span><?php if ($currentPage * 25 < (int) $counts['total']): ?><a href="?<?= e(http_build_query(['page' => 'admin', 'search' => $search, 'dentist' => $phone, 'p' => $currentPage + 1])) ?>">Próxima →</a><?php endif; ?></nav>
            </section>
        </div>
    <?php endif; ?>
<?php elseif ($page === 'privacy'): ?>
    <article class="card privacy"><p class="eyebrow">PRIVACIDADE</p><h1>Seus dados, com cuidado.</h1><p>A Esthetic Aligner recebe o nome completo do dentista, seu WhatsApp, o nome completo do paciente e os arquivos enviados para identificar, avaliar e realizar a submissão manual do caso no Ortholab.</p><p>O profissional remetente deve ter autorização e base legal para compartilhar os dados do paciente. Envie apenas os dados e arquivos necessários ao atendimento.</p><p>Os arquivos são armazenados em área privada, acessível à equipe administrativa autenticada. As notificações por e-mail contêm somente o protocolo e o link do painel, sem dados do paciente ou anexos.</p><p>Como o formulário não exige login, a identidade do remetente não é verificada automaticamente. Não utilize este canal para emergências.</p><p>Para informações sobre tratamento, retenção ou exclusão dos dados e exercício de direitos, entre em contato com <a href="mailto:tamires@estheticaligner.com.br">tamires@estheticaligner.com.br</a>. A retenção dos casos recebidos deve observar as obrigações clínicas e legais aplicáveis. Envios não concluídos são removidos após 24 horas pela rotina de manutenção.</p><a href="?">← Voltar ao envio</a></article>
<?php endif; ?>
</main>
<footer class="footer"><div class="footer-inner"><div class="footer-brand"><img src="assets/logo-esthetic-aligner-footer.png" alt="Esthetic Aligner" width="300" height="65" loading="lazy"><span>© <?= date('Y') ?> Esthetic Aligner. Todos os direitos reservados.</span></div><p>Envio Fácil<br>Mais praticidade para o seu dia a dia.</p><a href="?page=privacy">Privacidade e proteção de dados</a></div></footer>
</body>
</html>
