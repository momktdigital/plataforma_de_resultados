<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Configuracao;
use App\Models\PlanoAcao;
use App\Services\Portal\SmtpEmailSender;
use App\Support\NomeCurso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * E-mail sobre o plano de ação, para quem não está olhando a tela: o colaborador não tem sino de notificações, então só
 * saberia de um plano novo ao abrir o menu; e o coordenador pode demorar a ver a decisão.
 *
 *  - plano enviado ou reenviado → colaboradores (na falta de colaborador com e-mail, administradores);
 *  - decisão ou comentário do colaborador → coordenadores do curso;
 *  - comentário do coordenador → colaboradores.
 *
 * É um complemento, nunca uma dependência: sem SMTP configurado (Configurações → Portal público) nada é enviado, e uma falha
 * de envio vai só para o log — o fluxo do plano segue igual. Usa o mesmo remetente do código de acesso (SmtpEmailSender).
 */
class PlanoAcaoEmailService
{
    public function __construct(private readonly SmtpEmailSender $remetente) {}

    /** SMTP ativado (a chave "Ativar envio de e-mail" de Configurações → Portal público, desligada de fábrica) e preenchido. */
    public function configurado(): bool
    {
        return Configuracao::valor('smtp_ativo', '0') === '1' && trim((string) Configuracao::valor('smtp_host', '')) !== '' && trim((string) Configuracao::valor('smtp_from_email', '')) !== '';
    }

    /** Plano novo (ou reenviado) esperando análise. */
    public function planoEnviado(PlanoAcao $plano, bool $reenvio): void
    {
        $this->enviarPara(
            $this->revisores(),
            ($reenvio ? 'Plano de ação reenviado' : 'Novo plano de ação').' para análise',
            "O coordenador de <strong>".e($plano->curso).'</strong> '.($reenvio ? 'reenviou' : 'enviou').' um plano de ação para análise.',
            $plano,
            route('colaborador.planos.show', $plano),
            'Analisar o plano',
        );
    }

    /** O colaborador decidiu (aprovou, pediu ajustes ou recusou) ou comentou: avisa o coordenador do curso. */
    public function decisaoOuComentario(PlanoAcao $plano, string $titulo, ?string $texto): void
    {
        $this->enviarPara($this->coordenadoresDe($plano), $titulo, $texto !== null && $texto !== '' ? nl2br(e(Str::limit($texto, 600))) : '', $plano, route('coordenador.planos.show', $plano), 'Abrir o plano');
    }

    /** O coordenador comentou: avisa os colaboradores. */
    public function comentarioDoCoordenador(PlanoAcao $plano, string $texto): void
    {
        $this->enviarPara($this->revisores(), 'Novo comentário num plano de ação', nl2br(e(Str::limit($texto, 600))), $plano, route('colaborador.planos.show', $plano), 'Abrir o plano');
    }

    /**
     * @param  Collection<int, Admin>  $destinatarios
     */
    private function enviarPara(Collection $destinatarios, string $assunto, string $texto, PlanoAcao $plano, string $url, string $botao): void
    {
        if (! $this->configurado()) {
            return;
        }

        $site = (string) Configuracao::valor('site_title', 'Resultados DI');
        $corpo = '<p style="font-family:Arial,sans-serif;font-size:15px;color:#1e293b">'.$texto.'</p>'
            .'<p style="font-family:Arial,sans-serif;font-size:14px;color:#334155"><strong>'.e($plano->origem_rotulo).'</strong><br>'.e($plano->curso).($plano->periodo_letivo !== '' ? ' · '.e($plano->periodo_letivo) : '').'</p>'
            .'<p><a href="'.e($url).'" style="font-family:Arial,sans-serif;font-size:14px;color:#047857;font-weight:bold">'.e($botao).'</a></p>'
            .'<p style="font-family:Arial,sans-serif;font-size:12px;color:#64748b">Você recebeu este e-mail porque participa do fluxo de planos de ação do '.e($site).'.</p>';

        foreach ($destinatarios as $admin) {
            try {
                $this->remetente->enviar((string) $admin->email, "[{$site}] {$assunto}", $corpo);
            } catch (\Throwable $e) {
                Log::warning('E-mail do plano de ação não enviado: '.$e->getMessage(), ['plano' => $plano->id, 'admin' => $admin->id]);
            }
        }
    }

    /** @return Collection<int, Admin> colaboradores com e-mail; sem nenhum, os administradores com e-mail */
    private function revisores(): Collection
    {
        $colaboradores = Admin::colaboradores()->whereNotNull('email')->where('email', '!=', '')->get();

        return $colaboradores->isNotEmpty() ? $colaboradores : Admin::administradores()->whereNotNull('email')->where('email', '!=', '')->get();
    }

    /** @return Collection<int, Admin> */
    private function coordenadoresDe(PlanoAcao $plano): Collection
    {
        return Admin::coordenadores()->whereNotNull('email')->where('email', '!=', '')->get()
            ->filter(fn (Admin $c) => NomeCurso::estaEm($plano->curso, $c->cursos()))
            ->values();
    }
}
