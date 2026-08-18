@extends('layouts.portal')

@section('title', 'Consultar resultado')

@section('content')
<div class="text-center mb-8">
    <h1 class="text-2xl font-bold">Consultar resultado</h1>
    <p class="text-slate-500 mt-1">Informe seu CPF e Data de Nascimento para acessar seu boletim.</p>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <form method="POST" action="{{ route('portal.consultar') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1" for="cpf">CPF</label>
            <input id="cpf" name="cpf" type="text" required placeholder="000.000.000-00" value="{{ old('cpf') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="data_nascimento">Data de nascimento</label>
            <input id="data_nascimento" name="data_nascimento" type="text" required placeholder="DD/MM/AAAA" value="{{ old('data_nascimento') }}"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
        </div>

        @if ($recaptchaAtivo)
            <div class="g-recaptcha" data-sitekey="{{ $recaptchaSiteKey }}"></div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        @elseif ($hcaptchaAtivo)
            <div class="h-captcha" data-sitekey="{{ $hcaptchaSiteKey }}"></div>
            <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
        @endif

        <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg px-5 py-2.5 text-sm">
            Consultar
        </button>
    </form>
</div>

<script src="https://unpkg.com/imask"></script>
<script>
    IMask(document.getElementById('cpf'), {mask: '000.000.000-00'});
    IMask(document.getElementById('data_nascimento'), {
        mask: Date,
        pattern: 'd/m/Y',
        blocks: {
            d: {mask: IMask.MaskedRange, from: 1, to: 31, maxLength: 2},
            m: {mask: IMask.MaskedRange, from: 1, to: 12, maxLength: 2},
            Y: {mask: IMask.MaskedRange, from: 1900, to: 2999},
        },
        format: function (date) {
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            return [day, month, date.getFullYear()].join('/');
        },
        parse: function (str) {
            var partes = str.split('/');
            return new Date(partes[2], partes[1] - 1, partes[0]);
        },
    });
</script>
@endsection
