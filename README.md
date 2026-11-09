# Envio Fácil — Esthetic Aligner

Portal público para receber casos em `https://enviofacil.estheticaligner.com.br`, com painel administrativo independente em `/?page=login`.

## Identidade visual

Segue o site institucional `estheticaligner.com.br`: azul principal `#4071CE`, azul claro `#6EC1E4`, títulos leves em Lato e botões arredondados com Open Sans. Cabeçalho e rodapé usam os logotipos oficiais já publicados no site. Fontes e imagens são servidas localmente, sem enviar dados do formulário a serviços externos. As mesmas regras visuais atendem o formulário, as confirmações, o login e o painel, com adaptações para dispositivos móveis.

## Funcionalidades

- Nome completo do dentista, WhatsApp e nome completo do paciente obrigatórios.
- Sem cadastro para o remetente; a identidade informada não é automaticamente verificada.
- Até 20 arquivos e **500 MiB (524.288.000 bytes)** por caso; STL, JPG, PNG e ZIP.
- Upload em blocos de 2 MiB, progresso, repetição segura de blocos e retomada na mesma página após falhas. Atualizar a página exige iniciar outro envio.
- Arquivos fora da pasta pública; downloads somente para administradores autenticados.
- Dentistas agrupados pelo WhatsApp autodeclarado, com nome completo exibido; mudanças de número geram grupos separados.
- Pesquisa por dentista, paciente, telefone e protocolo, paginação e situação do caso.
- Notificação para `tamires@estheticaligner.com.br` com protocolo e link, sem dados do paciente ou anexos, processada pela rotina privada a cada cinco minutos.
- Histórico de início de downloads com usuário e horário de Brasília. A transmissão pelo servidor não comprova salvamento no dispositivo.
- Usuários próprios, senhas com hash, troca de senha, expiração da sessão após 30 minutos de inatividade, proteção CSRF e limites de tentativas.

## Instalação

PHP 8.2+ com `mysqli`, `mbstring`, `zip`, `session` e WordPress existente. Não requer Node em produção.

1. Coloque o projeto fora da raiz pública, por exemplo `/home/USER/enviofacil-app`.
2. Execute:

   ```sh
   php bin/install.php /home/USER/domains/estheticaligner.com.br/public_html/wp-load.php /home/USER/enviofacil-private
   ```

   O instalador gera a configuração privada e cinco tabelas com prefixo `enviofacil_` no banco existente. Não altera tabelas do WordPress.
3. Configure o subdomínio com HTTPS e pasta `public_html/enviofacil`. Copie os arquivos estáticos e `.htaccess` de `public/` para essa pasta (diretórios públicos com permissão 755, arquivos estáticos 644) e use um `index.php` de entrada:

   ```php
   <?php
   require '/home/USER/enviofacil-app/public/index.php';
   ```

4. Abra `/?page=setup`, informe o código armazenado em `enviofacil-private/activation-code.txt` (válido por 24 horas) e crie seu usuário e uma senha exclusiva de pelo menos 14 caracteres. A ativação é bloqueada depois do primeiro administrador.
5. Cadastre cron a cada cinco minutos:

   ```sh
   /usr/bin/php /home/USER/enviofacil-app/bin/maintenance.php >> /home/USER/enviofacil-private/maintenance.log 2>&1
   ```

   Ajuste o caminho do PHP à hospedagem. A rotina tenta novamente notificações com falha (até dez tentativas), limpa envios incompletos após 24 horas e remove contadores expirados.
6. Configure a entrega de e-mail do WordPress/hospedagem, SPF e DKIM. `wp_mail` aceitar o envio **não comprova entrega na caixa de entrada**. Confirme o recebimento real e acompanhe falhas no painel.

## Operação e dados

`config.php` e o diretório privado não devem ser publicados ou versionados. Nas requisições HTTP, `SHORTINIT` carrega somente a base do WordPress e a conexão ao banco, sem executar plugins, temas ou seu login. A rotina CLI carrega o WordPress completo para aproveitar o transporte de e-mail existente. O cron é obrigatório para notificar e limpar envios abandonados.

ZIPs não são extraídos no servidor. Arquivos criptografados, caminhos relativos perigosos, executáveis comuns e ZIPs com expansão superior a 2 GiB são rejeitados. Essas validações não substituem antivírus: confira arquivos antes de abri-los na estação clínica.

Casos concluídos são preservados; defina a política de retenção clínica/LGPD e backups antes da operação com dados reais. Faça backup conjunto das cinco tabelas e do armazenamento privado. Não há exclusão automática de casos concluídos.

## Validação

```sh
find src public bin tests -name '*.php' -exec php -l {} \;
php tests/validation.php
```

O teste usa arquivos temporários sintéticos e os remove ao terminar. Testes de integração devem usar dados fictícios e limpar somente os registros criados pelo teste.

`php tests/http.php` executa a integração no domínio configurado e envia **um e-mail real de teste**. Requer a instalação publicada, extensão cURL e banco configurado; remove os dois casos e o usuário sintéticos ao terminar. Não execute simultaneamente com a ativação inicial do administrador. Para apontar diretamente ao servidor durante propagação DNS, defina `ENVIOFACIL_TEST_IP` no ambiente. Não utiliza pacientes reais nem grava senhas no repositório.

A rotina grava `maintenance-state.json` no diretório privado, com o horário e a situação da última execução, para conferir se o agendamento está funcionando.
