'use strict';

const form = document.querySelector('#upload-form');
if (form) {
    const picker = document.querySelector('#files');
    const zone = document.querySelector('#drop-zone');
    const list = document.querySelector('#file-list');
    const summary = document.querySelector('#file-summary');
    const message = document.querySelector('#upload-message');
    const button = document.querySelector('#submit-button');
    const progress = document.querySelector('#progress-bar');
    const progressLabel = document.querySelector('#progress-label');
    const maxBytes = 500 * 1024 * 1024;
    const chunkBytes = 2 * 1024 * 1024;
    let files = [];
    let busy = false;
    let currentCase = null;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const totalBytes = () => files.reduce((total, file) => total + file.size, 0);
    const megabytes = bytes => (bytes / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
    const showError = text => {
        message.textContent = text;
        message.className = 'notice error';
    };

    function renderFiles() {
        list.replaceChildren();
        files.forEach((file, index) => {
            const item = document.createElement('li');
            const description = document.createElement('div');
            const name = document.createElement('strong');
            const size = document.createElement('small');
            const remove = document.createElement('button');
            name.textContent = file.name;
            size.textContent = `${megabytes(file.size)} MB`;
            remove.type = 'button';
            remove.className = 'remove-file';
            remove.textContent = 'Remover';
            remove.setAttribute('aria-label', `Remover ${file.name}`);
            remove.disabled = busy || currentCase !== null;
            remove.addEventListener('click', () => {
                files.splice(index, 1);
                renderFiles();
            });
            description.append(name, size);
            item.append(description, remove);
            list.append(item);
        });
        summary.textContent = files.length ? `${files.length}/20 arquivo(s) · ${megabytes(totalBytes())}/500 MB` : '';
    }

    function addFiles(incoming) {
        if (busy || currentCase) return;
        const next = [...files];
        for (const file of incoming) {
            if (!/\.(stl|jpe?g|png|zip)$/i.test(file.name)) {
                showError(`Formato não permitido: ${file.name}. Use STL, JPG, PNG ou ZIP.`);
                return;
            }
            if (!file.size) {
                showError(`O arquivo ${file.name} está vazio.`);
                return;
            }
            if (!next.some(existing => existing.name === file.name && existing.size === file.size && existing.lastModified === file.lastModified)) next.push(file);
        }
        if (next.length > 20 || next.reduce((sum, file) => sum + file.size, 0) > maxBytes) {
            showError('Selecione até 20 arquivos, com no máximo 500 MB no total.');
            return;
        }
        files = next;
        message.textContent = '';
        message.className = '';
        renderFiles();
    }

    picker.addEventListener('change', () => { addFiles(picker.files); picker.value = ''; });
    ['dragenter', 'dragover'].forEach(event => zone.addEventListener(event, e => {
        e.preventDefault();
        if (!busy) zone.classList.add('dragging');
    }));
    ['dragleave', 'drop'].forEach(event => zone.addEventListener(event, e => {
        e.preventDefault();
        zone.classList.remove('dragging');
    }));
    zone.addEventListener('drop', e => addFiles(e.dataTransfer.files));
    window.addEventListener('beforeunload', e => {
        if (busy) { e.preventDefault(); e.returnValue = ''; }
    });

    async function request(action, body, extra = {}, isChunk = false) {
        const url = new URL(location.href);
        url.search = new URLSearchParams({ action, ...extra }).toString();
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': isChunk ? 'application/octet-stream' : 'application/json',
                'X-CSRF-Token': csrf,
                ...(currentCase ? { 'X-Upload-Token': currentCase.token } : {}),
            },
            body: isChunk ? body : JSON.stringify(body),
        });
        let data;
        try { data = await response.json(); } catch {
            throw new Error('O servidor não respondeu como esperado. Verifique a conexão e tente novamente.');
        }
        if (!response.ok) throw new Error(data.error || `Falha no envio (${response.status}).`);
        return data;
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (busy || !form.reportValidity()) return;
        if (!files.length) { showError('Adicione ao menos um arquivo para enviar o caso.'); return; }
        busy = true;
        button.disabled = true;
        message.textContent = '';
        message.className = '';
        document.querySelector('#upload-progress').hidden = false;
        picker.disabled = true;
        renderFiles();
        let success = false;
        try {
            if (!currentCase) {
                currentCase = await request('create', {
                    dentist: form.elements.dentist.value,
                    whatsapp: form.elements.whatsapp.value,
                    patient: form.elements.patient.value,
                    website: form.elements.website.value,
                    consent: form.elements.consent.checked,
                });
                currentCase.uploads = [];
                form.querySelectorAll('input:not([type="file"])').forEach(input => { input.disabled = true; });
            }
            let completedBytes = 0;
            for (let index = 0; index < files.length; index++) {
                const file = files[index];
                let upload = currentCase.uploads[index];
                if (!upload) {
                    upload = await request('begin-file', { name: file.name, size: file.size }, { case: currentCase.id });
                    upload.offset = 0;
                    upload.verified = false;
                    currentCase.uploads[index] = upload;
                }
                while (upload.offset < file.size) {
                    progressLabel.textContent = `Enviando ${index + 1}/${files.length}: ${file.name}`;
                    const chunk = file.slice(upload.offset, upload.offset + chunkBytes);
                    let result;
                    for (let attempt = 0; attempt < 3; attempt++) {
                        try {
                            result = await request('chunk', chunk, { case: currentCase.id, file: upload.id, offset: upload.offset }, true);
                            break;
                        } catch (error) {
                            if (attempt === 2) throw error;
                            await new Promise(resolve => setTimeout(resolve, (attempt + 1) * 1500));
                        }
                    }
                    upload.offset = result.received;
                    progress.value = Math.floor(((completedBytes + upload.offset) / totalBytes()) * 100);
                }
                if (!upload.verified) {
                    progressLabel.textContent = `Verificando ${file.name}…`;
                    await request('complete-file', { file: upload.id }, { case: currentCase.id });
                    upload.verified = true;
                }
                completedBytes += file.size;
            }
            progressLabel.textContent = 'Concluindo o envio…';
            const result = await request('submit', {}, { case: currentCase.id });
            document.querySelector('#protocol').textContent = result.protocol;
            document.querySelector('#notification-note').textContent = result.notification === 'sent'
                ? 'A equipe foi notificada. Agora faremos a conferência e a submissão manual no Ortholab.'
                : 'Seu caso foi salvo. A equipe será notificada por e-mail pela rotina automática em até cinco minutos.';
            document.querySelector('.upload-layout').hidden = true;
            document.querySelector('.intro').hidden = true;
            const confirmation = document.querySelector('#success');
            confirmation.hidden = false;
            confirmation.focus();
            success = true;
        } catch (error) {
            showError(`${error.message} ${currentCase ? `Protocolo reservado: ${currentCase.protocol}. Você pode tentar continuar nesta página. Para corrigir dados ou substituir um arquivo inválido, atualize a página e inicie outro envio.` : ''}`);
        } finally {
            busy = false;
            if (!success) {
                button.disabled = false;
                button.textContent = currentCase ? 'Continuar envio' : 'Enviar caso ↗';
                picker.disabled = currentCase !== null;
                renderFiles();
            }
        }
    });
}
