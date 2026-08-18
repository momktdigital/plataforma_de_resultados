<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A tabela `configuracoes` pertence à aplicação legada (database.sql) e já
// existe em produção — esta migration só a cria quando ausente (ambientes
// novos/testes), nunca altera uma tabela existente. Mesmo padrão das demais
// tabelas compartilhadas (ver create_alunos_table.php). Os valores padrão
// espelham os `INSERT IGNORE` do database.sql legado, para a tela
// "Portal público" funcionar num banco novo sem passos manuais extras.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configuracoes')) {
            return;
        }

        Schema::create('configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 100)->unique();
            $table->text('valor')->nullable();
            $table->string('descricao')->nullable();
        });

        DB::table('configuracoes')->insert([
            ['chave' => 'recaptcha_ativo', 'valor' => '0', 'descricao' => 'Ativar reCAPTCHA (1 = Sim, 0 = Não)'],
            ['chave' => 'recaptcha_site_key', 'valor' => '', 'descricao' => 'Chave de Site (Site Key) do Google reCAPTCHA v2'],
            ['chave' => 'recaptcha_secret_key', 'valor' => '', 'descricao' => 'Chave Secreta (Secret Key) do Google reCAPTCHA v2'],
            ['chave' => 'hcaptcha_ativo', 'valor' => '0', 'descricao' => 'Ativar hCaptcha (1 = Sim, 0 = Não)'],
            ['chave' => 'hcaptcha_site_key', 'valor' => '', 'descricao' => 'Chave de Site (Site Key) do hCaptcha'],
            ['chave' => 'hcaptcha_secret_key', 'valor' => '', 'descricao' => 'Chave Secreta (Secret Key) do hCaptcha'],
            ['chave' => 'smtp_ativo', 'valor' => '0', 'descricao' => 'Ativar envio de email 2FA (1 = Sim, 0 = Não)'],
            ['chave' => 'smtp_host', 'valor' => 'email-smtp.sa-east-1.amazonaws.com', 'descricao' => 'Host do SMTP'],
            ['chave' => 'smtp_port', 'valor' => '587', 'descricao' => 'Porta do SMTP'],
            ['chave' => 'smtp_user', 'valor' => '', 'descricao' => 'Usuário do SMTP'],
            ['chave' => 'smtp_pass', 'valor' => '', 'descricao' => 'Senha do SMTP'],
            ['chave' => 'smtp_from_email', 'valor' => 'no-reply@seudominio.com.br', 'descricao' => 'Email do remetente'],
            ['chave' => 'smtp_from_name', 'valor' => 'Resultados DI', 'descricao' => 'Nome do remetente'],
        ]);
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver comentário acima.
    }
};
