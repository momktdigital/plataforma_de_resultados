<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O `database.sql` legado define `cpf`/`data_nascimento` como NOT NULL — regra
// que faz sentido para o cadastro manual (admin/aluno_form.php exige os dois,
// pois são a credencial de login do portal público). A importação de
// matrícula por planilha (admin/alunos_di_process.php) sempre tratou os dois
// como opcionais, mas nunca existiu uma migration tornando-os nullable —
// então, contra o schema real, todo INSERT com CPF/nascimento ausentes
// falhava. `cpf` continua UNIQUE: múltiplos NULLs são permitidos pelo
// MySQL/MariaDB nessa constraint, então não há conflito entre alunos ainda
// sem CPF cadastrado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            // Sem ->unique() aqui: a coluna já é UNIQUE desde a criação da
            // tabela: redeclarar a constraint faz o SQLite (usado nos
            // testes) tentar recriar o mesmo índice duas vezes.
            $table->string('cpf', 20)->nullable()->change();
            $table->date('data_nascimento')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            $table->string('cpf', 20)->nullable(false)->change();
            $table->date('data_nascimento')->nullable(false)->change();
        });
    }
};
