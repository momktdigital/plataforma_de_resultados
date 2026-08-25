<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Instalado" aqui significa: existe uma conexão de banco funcionando, a
 * tabela `admins` existe e tem pelo menos um usuário. Não depende de nenhum
 * arquivo-marcador — funciona tanto para uma instalação nova quanto para um
 * deploy apontando para o banco compartilhado que a aplicação legada já
 * populou.
 */
class InstallStatus
{
    public static function instalado(): bool
    {
        try {
            return Schema::hasTable('admins') && DB::table('admins')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function bancoConecta(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
