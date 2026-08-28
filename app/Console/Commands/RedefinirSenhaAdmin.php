<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\AtividadeLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Último recurso pra redefinir a senha de um admin: quando não há SMTP
 * configurado (fluxo de "esqueci minha senha" indisponível) nem outra conta
 * de admin pra fazer isso pela tela — exige acesso ao servidor, que já é
 * mais acesso do que qualquer conta de admin dá sozinha.
 */
class RedefinirSenhaAdmin extends Command
{
    protected $signature = 'admin:redefinir-senha {username}';

    protected $description = 'Redefine a senha de um administrador direto pelo servidor';

    public function handle(): int
    {
        $admin = Admin::where('username', $this->argument('username'))->first();

        if ($admin === null) {
            $this->error("Administrador '{$this->argument('username')}' não encontrado.");

            return self::FAILURE;
        }

        $senha = $this->secret('Nova senha (mínimo 10 caracteres)');
        $confirmacao = $this->secret('Confirme a nova senha');

        if ($senha !== $confirmacao) {
            $this->error('As senhas não coincidem.');

            return self::FAILURE;
        }

        if (strlen((string) $senha) < 10) {
            $this->error('A senha precisa ter ao menos 10 caracteres.');

            return self::FAILURE;
        }

        $admin->password_hash = Hash::make($senha);
        $admin->save();

        AtividadeLogger::registrar(
            'administrador.senha_redefinida_via_cli',
            'Admin',
            $admin->id,
            ['username' => $admin->username],
            origemSemAuth: 'CLI: admin:redefinir-senha',
        );

        $this->info("Senha de '{$admin->username}' redefinida com sucesso.");

        return self::SUCCESS;
    }
}
