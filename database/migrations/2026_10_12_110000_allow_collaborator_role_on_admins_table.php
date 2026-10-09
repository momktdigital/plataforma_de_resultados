<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Perfil de colaborador (`admins.role = 'collaborator'`): quem monta o cronograma de atividades dos coordenadores.
//
// Mesmo problema do perfil de reitor (ver allow_rector_role_on_admins_table): no banco legado `admins.role` é um
// ENUM e o MySQL recusa um valor que não está na lista ("Data truncated for column 'role'"). Aqui o ENUM ganha
// 'collaborator', preservando os valores que já existem, a obrigatoriedade e o padrão. Em instalações novas (e no SQLite
// dos testes) a coluna é VARCHAR e não há o que mudar.
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
        if (in_array('collaborator', $valores, true)) {
            return;
        }

        $lista = implode(',', array_map(fn ($v) => DB::getPdo()->quote($v), [...$valores, 'collaborator']));
        $nulo = $coluna->Null === 'YES' ? 'NULL' : 'NOT NULL';
        $padrao = $coluna->Default !== null ? ' DEFAULT '.DB::getPdo()->quote($coluna->Default) : '';

        DB::statement("ALTER TABLE `admins` MODIFY `role` ENUM({$lista}) {$nulo}{$padrao}");
    }

    public function down(): void
    {
        // Sem volta: tirar 'collaborator' do ENUM apagaria (ou recusaria) as contas de colaborador já criadas.
    }
};
