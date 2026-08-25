@extends('layouts.portal')

@section('title', 'Consultar resultado')

@section('content')
<div class="w-full max-w-md mx-auto bg-white rounded-2xl shadow-xl border border-slate-100 p-8 sm:p-10 fade-in">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 mb-4">
            <i class="ph ph-magnifying-glass text-3xl text-primary"></i>
        </div>
        <h1 class="text-2xl font-bold mb-2">Consulte seu Resultado</h1>
        <p class="text-slate-500 text-sm">Informe seu CPF e Data de Nascimento para acessar seu boletim.</p>
    </div>

    <form method="POST" action="{{ route('portal.consultar') }}" class="space-y-6">
        @csrf
        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1 ml-1" for="cpf">CPF</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="ph ph-identification-card text-slate-400 text-xl"></i>
                </div>
                <input id="cpf" name="cpf" type="text" required placeholder="000.000.000-00" value="{{ old('cpf') }}"
                       class="block w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-base font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner">
            </div>
        </div>
        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1 ml-1" for="data_nascimento">Data de nascimento</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i class="ph ph-calendar-blank text-slate-400 text-xl"></i>
                </div>
                <input id="data_nascimento" name="data_nascimento" type="text" required placeholder="DD/MM/AAAA" value="{{ old('data_nascimento') }}"
                       class="block w-full pl-12 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-base font-medium text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all shadow-inner">
            </div>
        </div>

        @if ($recaptchaAtivo)
            <div class="flex justify-center">
                <div class="g-recaptcha" data-sitekey="{{ $recaptchaSiteKey }}"></div>
            </div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        @elseif ($hcaptchaAtivo)
            <div class="flex justify-center">
                <div class="h-captcha" data-sitekey="{{ $hcaptchaSiteKey }}"></div>
            </div>
            <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
        @endif

        <button type="submit"
                class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-xl shadow-md text-base font-bold text-white bg-primary hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-all hover:-translate-y-0.5">
            <span>Consultar Resultado</span>
            <i class="ph-bold ph-arrow-right ml-2 text-lg"></i>
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
