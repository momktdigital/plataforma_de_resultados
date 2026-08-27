<?php

namespace App\Services\Auth;

use App\Models\Admin;
use App\Models\Configuracao;
use App\Models\RedefinicaoSenha;
use App\Services\Portal\SmtpEmailSender;
use App\Support\AtividadeLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fluxo de "esqueci minha senha" pro login de admin — combinado com a
 * ausência de edição de administrador, um admin sozinho (sem outra conta pra
 * pedir reset) que esquecesse a senha não tinha caminho de recuperação
 * nenhum. Requer SMTP configurado (Configurações → Portal público) e a conta
 * ter um e-mail cadastrado — sem isso, degrada silenciosamente (ver
 * solicitar()) em vez de expor por que o e-mail não chegou.
 */
class PasswordResetService
{
    private const EXPIRA_MINUTOS = 60;

    public function __construct(private readonly SmtpEmailSender $mailer) {}

    public function disponivel(): bool
    {
        return Configuracao::valor('smtp_ativo', '0') === '1';
    }

    /**
     * Sempre silenciosa quanto ao resultado — nunca revela se o usuário
     * existe, se tem e-mail cadastrado, ou se o envio falhou. Um endpoint de
     * "esqueci minha senha" que responde diferente pra usuário
     * existente/inexistente é um oráculo de enumeração de contas.
     */
    public function solicitar(string $username): void
    {
        $admin = Admin::where('username', $username)->first();

        if ($admin === null || empty($admin->email) || ! $this->disponivel()) {
            return;
        }

        RedefinicaoSenha::where('admin_id', $admin->id)->delete();

        $token = Str::random(64);

        RedefinicaoSenha::create([
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addMinutes(self::EXPIRA_MINUTOS),
        ]);

        $url = route('senha.redefinir.edit', $token);
        $site = Configuracao::valor('site_title', 'Resultados');

        $this->mailer->enviar(
            $admin->email,
            "Redefinição de senha — {$site}",
            "Olá, <b>{$admin->username}</b>.<br><br>"
                .'Recebemos um pedido de redefinição de senha da sua conta de administrador. '
                .'Clique no link abaixo para escolher uma nova senha (válido por 1 hora):<br><br>'
                ."<a href=\"{$url}\">{$url}</a><br><br>"
                .'Se você não solicitou isso, pode ignorar este e-mail — sua senha continua a mesma.',
        );
    }

    public function validar(string $token): ?RedefinicaoSenha
    {
        return RedefinicaoSenha::where('token_hash', hash('sha256', $token))
            ->where('expira_em', '>', now())
            ->first();
    }

    public function redefinir(RedefinicaoSenha $redefinicao, string $novaSenha): void
    {
        $admin = $redefinicao->admin;
        $admin->password_hash = Hash::make($novaSenha);
        $admin->save();

        RedefinicaoSenha::where('admin_id', $admin->id)->delete();

        AtividadeLogger::registrar(
            'administrador.senha_redefinida_via_email',
            'Admin',
            $admin->id,
            ['username' => $admin->username],
            origemSemAuth: "{$admin->username} (via link de e-mail)",
        );
    }
}
