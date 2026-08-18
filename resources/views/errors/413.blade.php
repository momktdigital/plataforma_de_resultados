<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Arquivo muito grande — Avaliações</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-lg w-full bg-white border border-slate-200 rounded-xl shadow-sm p-8">
        <h1 class="text-xl font-bold text-red-700 mb-2">Arquivo maior do que este servidor aceita</h1>
        <p class="text-sm text-slate-600 mb-4">
            O envio foi recusado antes mesmo de chegar à aplicação — o tamanho passou do limite configurado no
            próprio servidor (PHP e/ou proxy web), não um limite desta tela.
        </p>

        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 text-sm text-slate-700 space-y-2 mb-6">
            <p class="font-semibold">Para aceitar arquivos maiores, ajuste no servidor:</p>
            <ul class="list-disc pl-5 space-y-1">
                <li><code>post_max_size</code> e <code>upload_max_filesize</code> no <code>php.ini</code> (e reinicie o PHP-FPM/Apache).</li>
                <li>Se houver Nginx na frente: <code>client_max_body_size</code>.</li>
            </ul>
        </div>

        <a href="javascript:history.back()" class="inline-block text-emerald-700 font-semibold hover:underline text-sm">
            &larr; Voltar
        </a>
    </div>
</body>
</html>
