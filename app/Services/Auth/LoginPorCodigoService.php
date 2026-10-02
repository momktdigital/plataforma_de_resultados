<?php

namespace App\Services\Auth;

use App\Models\Admin;
use App\Models\Configuracao;
use App\Models\LoginCodigo;
use App\Services\Portal\SmtpEmailSender;
use Illuminate\Support\Carbon;

/**
 * Login de COORDENADOR sem senha: o sistema envia um código de 6 dígitos para
 * o e-mail cadastrado e o coordenador o digita. (Administrador continua
 * entrando com usuário e senha — para ele a senha é obrigatória.)
 *
 * Mesmas regras do 2FA do aluno (PortalController): código de 10 minutos, 3
 * tentativas erradas invalidam, reenvio com espera crescente. Diferenças: só o
 * hash do código é guardado (HMAC com a chave do app) e a conta nunca é
 * revelada — quem chama devolve sempre a mesma mensagem, exista ou não o
 * usuário (evita enumeração de contas).
 *
 * Usa o SMTP do portal (Configurações → Portal público) e exige que esteja
 * ativado, como PasswordResetService.
 */
class LoginPorCodigoService
{
    public const EXPIRA_MINUTOS = 10;

    public const MAX_TENTATIVAS = 3;

    /** Espera entre reenvios, em minutos (índice = vezes já reenviado). */
    private const ESPERAS_REENVIO = [1, 2, 5, 10];

    public const OK = 'ok';

    public const INVALIDO = 'invalido';

    public const EXPIRADO = 'expirado';

    public const BLOQUEADO = 'bloqueado';

    public function __construct(private readonly SmtpEmailSender $mailer) {}

    public function disponivel(): bool
    {
        return Configuracao::valor('smtp_ativo', '0') === '1';
    }

    /** Coordenador com e-mail cadastrado, pelo usuário OU pelo e-mail digitado. */
    public function localizar(string $identificador): ?Admin
    {
        $identificador = trim($identificador);
        if ($identificador === '') {
            return null;
        }

        return Admin::coordenadores()
            ->whereNotNull('email')->where('email', '!=', '')
            ->where(fn ($q) => $q->where('username', $identificador)->orWhere('email', $identificador))
            ->first();
    }

    /** Gera e envia um código novo (invalida o anterior). Pode lançar exceção de transporte do e-mail. */
    public function emitir(Admin $admin): void
    {
        $codigo = sprintf('%06d', random_int(0, 999999));

        LoginCodigo::where('admin_id', $admin->id)->delete();
        LoginCodigo::create([
            'admin_id' => $admin->id,
            'codigo_hash' => $this->hash($codigo),
            'expira_em' => Carbon::now()->addMinutes(self::EXPIRA_MINUTOS),
        ]);

        $this->enviar($admin, $codigo, '');
    }

    /**
     * Reenvia respeitando a espera crescente.
     *
     * @return array{enviado: bool, espera: int} espera = minutos que faltam (quando não enviou por causa dela)
     */
    public function reenviar(Admin $admin): array
    {
        $registro = LoginCodigo::where('admin_id', $admin->id)->latest('id')->first();

        if ($registro === null) {
            $this->emitir($admin);

            return ['enviado' => true, 'espera' => 0];
        }

        $minutosEspera = $registro->ultimo_reenvio === null ? 1 : self::ESPERAS_REENVIO[min($registro->vezes_reenviado, 3)];
        $fimEspera = ($registro->ultimo_reenvio ?? $registro->criado_em)->copy()->addMinutes($minutosEspera);

        if (Carbon::now()->lt($fimEspera)) {
            return ['enviado' => false, 'espera' => (int) ceil(Carbon::now()->diffInSeconds($fimEspera) / 60)];
        }

        $codigo = sprintf('%06d', random_int(0, 999999));
        $registro->update([
            'codigo_hash' => $this->hash($codigo),
            'tentativas_falhas' => 0,
            'vezes_reenviado' => $registro->vezes_reenviado + 1,
            'ultimo_reenvio' => Carbon::now(),
            'expira_em' => Carbon::now()->addMinutes(self::EXPIRA_MINUTOS),
        ]);

        $this->enviar($admin, $codigo, '[Reenvio] ');

        return ['enviado' => true, 'espera' => 0];
    }

    /** @return string uma das constantes OK / INVALIDO / EXPIRADO / BLOQUEADO */
    public function verificar(Admin $admin, string $codigo): string
    {
        $registro = LoginCodigo::where('admin_id', $admin->id)->latest('id')->first();

        if ($registro === null) {
            return self::INVALIDO;
        }

        if ($registro->expira_em->isPast()) {
            return self::EXPIRADO;
        }

        if ($registro->tentativas_falhas >= self::MAX_TENTATIVAS) {
            return self::BLOQUEADO;
        }

        // hash_equals: tempo constante — a duração da resposta não vaza o quanto do código acertou.
        if (! hash_equals($registro->codigo_hash, $this->hash(trim($codigo)))) {
            $registro->increment('tentativas_falhas');

            return $registro->tentativas_falhas >= self::MAX_TENTATIVAS ? self::BLOQUEADO : self::INVALIDO;
        }

        // Uso único: o código vale uma vez só.
        LoginCodigo::where('admin_id', $admin->id)->delete();

        return self::OK;
    }

    private function hash(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, (string) config('app.key'));
    }

    private function enviar(Admin $admin, string $codigo, string $prefixoAssunto): void
    {
        $site = Configuracao::valor('site_title', 'Resultados');

        $this->mailer->enviar(
            $admin->email,
            $prefixoAssunto."Seu código de acesso — {$site}",
            "Olá, <b>{$admin->username}</b>.<br><br>"
                ."Seu código de acesso é: <b>{$codigo}</b><br><br>"
                .'Este código expira em '.self::EXPIRA_MINUTOS.' minutos e só pode ser usado uma vez.<br><br>'
                .'Se você não solicitou este acesso, por favor ignore este e-mail.',
        );
    }
}
