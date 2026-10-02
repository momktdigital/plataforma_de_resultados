<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// O código de 2FA do portal passou a ser guardado como HMAC-SHA256 (64 caracteres hex) em vez de texto puro — ver
// App\Models\VerificacaoEmail::hashDoCodigo(). A coluna `codigo` (varchar(10) no schema legado) precisa caber nele.
// Códigos em texto puro ainda pendentes são descartados: valem 10 minutos e já não seriam aceitos.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verificacoes_email')) {
            return;
        }

        Schema::table('verificacoes_email', function (Blueprint $table) {
            $table->string('codigo', 64)->change();
        });

        DB::table('verificacoes_email')->delete();
    }

    public function down(): void
    {
        // Intencionalmente vazio: encolher a coluna perderia o hash e a tabela é compartilhada com o legado.
    }
};
