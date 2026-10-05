<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Perfil de reitor (`admins.role = 'rector'`).
//
// No banco da aplicação legada (database.sql) `admins.role` é um ENUM('superadmin','coordinator'): o MySQL recusa
// 'rector' ("Data truncated for column 'role'") e o cadastro do reitor dava erro 500. Aqui o ENUM ganha o valor novo,
// preservando os que já existem, a obrigatoriedade e o padrão da coluna. Em instalações novas (e no SQLite dos testes)
// a coluna é VARCHAR e não há o que mudar.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        $coluna = DB::selectOne("SHOW COLUMNS FROM `admins` LIKE 'role'");
        if ($coluna === null || ! preg_match('/^enum\((.*)\)$/i', (string) $coluna->Type, $m)) {
            return;
        }

        $valores = array_map(fn ($v) => trim($v, "'"), str_getcsv($m[1], ',', "'"));
        if (in_array('rector', $valores, true)) {
            return;
        }

        $lista = implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), [...$valores, 'rector']));
        $nulo = $coluna->Null === 'YES' ? 'NULL' : 'NOT NULL';
        $padrao = $coluna->Default !== null ? ' DEFAULT '.DB::getPdo()->quote($coluna->Default) : '';

        DB::statement("ALTER TABLE `admins` MODIFY `role` ENUM({$lista}) {$nulo}{$padrao}");
    }

    public function down(): void
    {
        // Sem volta: tirar 'rector' do ENUM apagaria (ou recusaria) as contas de reitor já criadas.
    }
};
