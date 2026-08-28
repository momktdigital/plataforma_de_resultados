@extends('layouts.app')

@section('title', 'Portal público — Avaliações')

@section('content')
<h1 class="text-2xl font-bold mb-6">Configurações do portal público</h1>

@include('admin.sistema._subnav')

<p class="text-sm text-slate-500 mb-6 max-w-2xl">
    Estas configurações alimentam o portal público (consulta de resultados por
    CPF + Data de Nascimento): aparência, CAPTCHA e o SMTP/template usados no
    2FA por e-mail.
</p>

<div class="space-y-8 max-w-2xl">
    {{-- Aparência --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">Aparência geral</h2>
        <form method="POST" action="{{ route('sistema.portal.aparencia') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-sm font-medium mb-1" for="site_title">Título do site</label>
                <input id="site_title" name="site_title" type="text" value="{{ old('site_title', $siteTitle) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="site_logo">Logo (fundo claro)</label>
                @if ($siteLogo)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogo)) }}" alt="Logo atual" class="h-10 object-contain border border-slate-200 rounded p-1 bg-white mb-2">
                @endif
                <input id="site_logo" name="site_logo" type="file" accept="image/*,.svg" class="w-full text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="site_logo_dark">Logo (fundo escuro)</label>
                @if ($siteLogoDark)
                    <img src="{{ asset('uploads/logos/'.basename($siteLogoDark)) }}" alt="Logo atual (escura)" class="h-10 object-contain border border-slate-600 rounded p-1 bg-slate-800 mb-2">
                @endif
                <input id="site_logo_dark" name="site_logo_dark" type="file" accept="image/*,.svg" class="w-full text-sm">
            </div>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Salvar
            </button>
        </form>
    </div>

    {{-- CAPTCHA --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-1">Segurança e anti-bot (CAPTCHA)</h2>
        <p class="text-sm text-slate-500 mb-4">Protege o login do admin e a consulta do aluno. Apenas um serviço pode estar ativo por vez.</p>
        <form method="POST" action="{{ route('sistema.portal.captcha') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div class="space-y-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="captcha_type" value="none" onchange="document.getElementById('campos-recaptcha').classList.add('hidden'); document.getElementById('campos-hcaptcha').classList.add('hidden');" {{ old('captcha_type', $captchaType) === 'none' ? 'checked' : '' }}>
                    Desativado
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="captcha_type" value="recaptcha" onchange="document.getElementById('campos-recaptcha').classList.remove('hidden'); document.getElementById('campos-hcaptcha').classList.add('hidden');" {{ old('captcha_type', $captchaType) === 'recaptcha' ? 'checked' : '' }}>
                    Google reCAPTCHA v2
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="captcha_type" value="hcaptcha" onchange="document.getElementById('campos-hcaptcha').classList.remove('hidden'); document.getElementById('campos-recaptcha').classList.add('hidden');" {{ old('captcha_type', $captchaType) === 'hcaptcha' ? 'checked' : '' }}>
                    hCaptcha
                </label>
            </div>

            <div id="campos-recaptcha" class="space-y-3 pt-3 border-t border-slate-100 {{ $captchaType === 'recaptcha' ? '' : 'hidden' }}">
                <div>
                    <label class="block text-sm font-medium mb-1" for="recaptcha_site_key">Site key (reCAPTCHA)</label>
                    <input id="recaptcha_site_key" name="recaptcha_site_key" type="text" value="{{ old('recaptcha_site_key', $recaptchaSiteKey) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="recaptcha_secret_key">Secret key (reCAPTCHA)</label>
                    <input id="recaptcha_secret_key" name="recaptcha_secret_key" type="password" value="{{ old('recaptcha_secret_key') }}"
                           placeholder="{{ $recaptchaSecretExists ? '******** (deixe em branco para não alterar)' : '' }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
            </div>

            <div id="campos-hcaptcha" class="space-y-3 pt-3 border-t border-slate-100 {{ $captchaType === 'hcaptcha' ? '' : 'hidden' }}">
                <div>
                    <label class="block text-sm font-medium mb-1" for="hcaptcha_site_key">Sitekey (hCaptcha)</label>
                    <input id="hcaptcha_site_key" name="hcaptcha_site_key" type="text" value="{{ old('hcaptcha_site_key', $hcaptchaSiteKey) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="hcaptcha_secret_key">Secret key (hCaptcha)</label>
                    <input id="hcaptcha_secret_key" name="hcaptcha_secret_key" type="password" value="{{ old('hcaptcha_secret_key') }}"
                           placeholder="{{ $hcaptchaSecretExists ? '******** (deixe em branco para não alterar)' : '' }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
            </div>

            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                Salvar
            </button>
        </form>
    </div>

    {{-- SMTP / 2FA --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
        <h2 class="font-semibold mb-4">E-mail (2FA)</h2>
        <form method="POST" action="{{ route('sistema.portal.smtp') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="smtp_ativo" value="1" {{ old('smtp_ativo', $smtpAtivo) ? 'checked' : '' }}>
                SMTP ativado (envia código de 2FA por e-mail)
            </label>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_from_name">Nome do remetente</label>
                    <input id="smtp_from_name" name="smtp_from_name" type="text" value="{{ old('smtp_from_name', $smtpFromName) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_from_email">E-mail do remetente</label>
                    <input id="smtp_from_email" name="smtp_from_email" type="email" value="{{ old('smtp_from_email', $smtpFromEmail) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_user">Login SMTP</label>
                    <input id="smtp_user" name="smtp_user" type="text" value="{{ old('smtp_user', $smtpUser) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_pass">Senha SMTP</label>
                    <input id="smtp_pass" name="smtp_pass" type="password"
                           placeholder="{{ $smtpPassExists ? '******** (deixe em branco para não alterar)' : '' }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_host">Host SMTP</label>
                    <input id="smtp_host" name="smtp_host" type="text" value="{{ old('smtp_host', $smtpHost) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="smtp_port">Porta SMTP</label>
                    <input id="smtp_port" name="smtp_port" type="text" value="{{ old('smtp_port', $smtpPort) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 space-y-3">
                <div class="rounded-lg bg-blue-50 border border-blue-200 text-blue-900 text-xs px-3 py-2">
                    Variáveis disponíveis no template: <strong>[NOME_DO_ALUNO]</strong> e <strong>[CODIGO]</strong>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="email_template_subject">Assunto do e-mail</label>
                    <input id="email_template_subject" name="email_template_subject" type="text" value="{{ old('email_template_subject', $emailTemplateSubject) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="email_template_body">Corpo do e-mail (HTML)</label>
                    <textarea id="email_template_body" name="email_template_body" rows="5"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono">{{ old('email_template_body', $emailTemplateBody) }}</textarea>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                    Salvar
                </button>
                <button type="button" onclick="abrirTesteSmtp()" class="bg-slate-600 hover:bg-slate-700 text-white font-semibold rounded-lg px-5 py-2 text-sm">
                    Testar envio
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal de teste SMTP --}}
<div id="modal-teste-smtp" class="hidden fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-lg p-6 relative">
        <button type="button" onclick="fecharTesteSmtp()" class="absolute top-3 right-3 text-slate-400 hover:text-slate-600">&times;</button>
        <h3 class="font-bold mb-1" id="teste-titulo">Testar envio de e-mail</h3>
        <p class="text-sm text-slate-500 mb-4" id="teste-desc">Informe um e-mail para receber o código de teste.</p>

        <div id="teste-passo-1">
            <input id="teste-email" type="email" placeholder="seu@email.com" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3">
            <button type="button" onclick="enviarTesteSmtp()" id="teste-btn-enviar" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Enviar código
            </button>
        </div>

        <div id="teste-passo-2" class="hidden">
            <input id="teste-codigo" maxlength="6" placeholder="000000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3 text-center tracking-widest font-bold uppercase">
            <button type="button" onclick="verificarTesteSmtp()" id="teste-btn-verificar" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg px-4 py-2 text-sm">
                Validar código
            </button>
        </div>

        <div id="teste-mensagem" class="hidden mt-3 p-3 rounded-lg text-sm"></div>
    </div>
</div>

<script>
function abrirTesteSmtp() {
    document.getElementById('modal-teste-smtp').classList.remove('hidden');
    document.getElementById('teste-passo-1').classList.remove('hidden');
    document.getElementById('teste-passo-2').classList.add('hidden');
    document.getElementById('teste-email').value = '';
    document.getElementById('teste-codigo').value = '';
    ocultarMensagemTeste();
}
function fecharTesteSmtp() {
    document.getElementById('modal-teste-smtp').classList.add('hidden');
}
function mostrarMensagemTeste(msg, tipo) {
    const el = document.getElementById('teste-mensagem');
    el.textContent = msg;
    el.className = 'mt-3 p-3 rounded-lg text-sm ' + (tipo === 'error' ? 'bg-red-50 text-red-800' : tipo === 'success' ? 'bg-emerald-50 text-emerald-800' : 'bg-blue-50 text-blue-800');
}
function ocultarMensagemTeste() {
    document.getElementById('teste-mensagem').classList.add('hidden');
}
async function enviarTesteSmtp() {
    const email = document.getElementById('teste-email').value.trim();
    if (!email) { mostrarMensagemTeste('Informe um e-mail válido.', 'error'); return; }

    const btn = document.getElementById('teste-btn-enviar');
    btn.disabled = true; btn.textContent = 'Enviando...';
    ocultarMensagemTeste();

    try {
        const res = await fetch('{{ route('sistema.portal.smtp.teste') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ email }),
        });
        const json = await res.json();
        if (res.ok && json.status === 'success') {
            document.getElementById('teste-passo-1').classList.add('hidden');
            document.getElementById('teste-passo-2').classList.remove('hidden');
            mostrarMensagemTeste('E-mail enviado! Verifique sua caixa de entrada.', 'info');
        } else {
            mostrarMensagemTeste(json.message || 'Erro ao enviar.', 'error');
        }
    } catch (e) {
        mostrarMensagemTeste('Falha ao processar a resposta do servidor.', 'error');
    }
    btn.disabled = false; btn.textContent = 'Enviar código';
}
async function verificarTesteSmtp() {
    const codigo = document.getElementById('teste-codigo').value.trim();
    if (codigo.length !== 6) { mostrarMensagemTeste('O código deve ter 6 dígitos.', 'error'); return; }

    const btn = document.getElementById('teste-btn-verificar');
    btn.disabled = true; btn.textContent = 'Validando...';
    ocultarMensagemTeste();

    try {
        const res = await fetch('{{ route('sistema.portal.smtp.teste.verificar') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ codigo }),
        });
        const json = await res.json();
        if (res.ok && json.status === 'success') {
            mostrarMensagemTeste('Sucesso! SMTP configurado corretamente.', 'success');
            setTimeout(fecharTesteSmtp, 2000);
        } else {
            mostrarMensagemTeste(json.message || 'Código inválido.', 'error');
        }
    } catch (e) {
        mostrarMensagemTeste('Falha ao processar a resposta do servidor.', 'error');
    }
    btn.disabled = false; btn.textContent = 'Validar código';
}
</script>
@endsection
