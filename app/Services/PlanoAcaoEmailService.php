<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ConfiguracaoSistema;
use App\Models\Configuracao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Services\Portal\SmtpEmailSender;
use App\Support\NomeCurso;
use App\Support\PlanoAcaoChecagem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * E-mail do plano de ação.
 *
 *  - PLANO PUBLICADO → os e-mails definidos em Configurações (`plano_emails`), com o plano COMPLETO: identificação, indicadores,
 *    avaliações, o dado que originou o plano, leitura, causas e ações. Quem acompanha não precisa abrir o sistema para ler o plano;
 *  - comentário de quem acompanha → os coordenadores do curso.
 *
 * É um complemento, nunca uma dependência: sem SMTP ativo (Configurações → Portal público) ou sem e-mail definido, nada é enviado, e
 * uma falha de envio vai só para o log — o fluxo do plano segue igual. Usa o mesmo remetente do código de acesso (SmtpEmailSender).
 */
class PlanoAcaoEmailService
{
    /** Chave (em `configuracoes_sistema`) com os e-mails que recebem o aviso de plano novo, separados por vírgula. */
    public const CHAVE = 'plano_emails';

    public function __construct(private readonly SmtpEmailSender $remetente) {}

    /** SMTP ativado (a chave "Ativar envio de e-mail" de Configurações → Portal público, desligada de fábrica) e preenchido. */
    public function configurado(): bool
    {
        return Configuracao::valor('smtp_ativo', '0') === '1' && trim((string) Configuracao::valor('smtp_host', '')) !== '' && trim((string) Configuracao::valor('smtp_from_email', '')) !== '';
    }

    /** @return array<int, string> os e-mails que recebem o aviso de plano novo (Configurações → Plano de ação) */
    public static function destinatarios(): array
    {
        return self::separar((string) ConfiguracaoSistema::valor(self::CHAVE, ''));
    }

    /**
     * "a@x.com, b@y.com\nc@z.com" → ['a@x.com', 'b@y.com', 'c@z.com'] (sem repetidos, caixa baixa, só endereços válidos).
     *
     * @return array<int, string>
     */
    public static function separar(string $texto): array
    {
        $emails = array_map(fn ($e) => mb_strtolower(trim($e)), preg_split('/[\s,;]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return array_values(array_unique(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /** O plano acabou de ser publicado: manda ele inteiro para a lista de Configurações. */
    public function novoPlano(PlanoAcao $plano): void
    {
        $destinatarios = self::destinatarios();
        if ($destinatarios === [] || ! $this->configurado()) {
            return;
        }

        $site = (string) Configuracao::valor('site_title', 'Resultados DI');
        $assunto = "Novo plano de ação: {$plano->origem_rotulo} — {$plano->curso}";

        foreach ($destinatarios as $email) {
            $this->enviar($email, "[{$site}] {$assunto}", $this->planoCompleto($plano, $site), $plano->id);
        }
    }

    /** Quem acompanha comentou: avisa os coordenadores do curso. */
    public function comentarioParaOCoordenador(PlanoAcao $plano, string $texto): void
    {
        if (! $this->configurado()) {
            return;
        }

        $site = (string) Configuracao::valor('site_title', 'Resultados DI');
        $corpo = $this->tipografia('<p style="font-size:15px;color:#1e293b">'.nl2br(e(Str::limit($texto, 600))).'</p>'
            .'<p style="font-size:14px;color:#334155"><strong>'.e($plano->origem_rotulo).'</strong><br>'.e($plano->curso).'</p>'
            .'<p><a href="'.e(route('coordenador.planos.show', $plano)).'" style="color:#047857;font-weight:bold">Abrir o plano</a></p>');

        foreach ($this->coordenadoresDe($plano) as $coordenador) {
            $this->enviar((string) $coordenador->email, "[{$site}] Novo comentário no plano de ação", $corpo, $plano->id);
        }
    }

    /**
     * O plano inteiro em HTML: tudo que o coordenador preencheu, mais a foto dos indicadores e o dado de origem.
     * Cada texto é escapado (vem de digitação livre).
     */
    public function planoCompleto(PlanoAcao $plano, string $site): string
    {
        $plano->loadMissing(['acoes', 'autor']);
        $ctx = $plano->contexto ?? [];
        $pct = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',').'%';
        $texto = fn (?string $t) => trim((string) $t) === '' ? '<em style="color:#64748b">Não informado</em>' : nl2br(e((string) $t));
        $campo = fn (string $rotulo, string $valorHtml) => '<tr><td style="padding:6px 10px;vertical-align:top;width:34%;font-weight:bold;color:#475569;border-bottom:1px solid #e2e8f0">'.e($rotulo).'</td>'
            .'<td style="padding:6px 10px;vertical-align:top;color:#1e293b;border-bottom:1px solid #e2e8f0">'.$valorHtml.'</td></tr>';
        $secao = fn (string $titulo, string $linhas) => '<h2 style="font-size:16px;color:#0f172a;margin:22px 0 6px">'.e($titulo).'</h2>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px">'.$linhas.'</table>';

        $html = '<h1 style="font-size:20px;color:#0f172a;margin:0 0 4px">Novo plano de ação</h1>'
            .'<p style="margin:0 0 6px;color:#334155">'.e($plano->origem_rotulo).'</p>';

        $html .= $secao('Identificação',
            $campo('Curso', e($plano->curso))
            .$campo('Período letivo', e($plano->periodo_letivo !== '' ? $plano->periodo_letivo : 'Todos os períodos'))
            .$campo('Categoria de avaliação', e($ctx['categoria'] ?? 'Todas as categorias do período'))
            .$campo('Criado por', e($plano->autor?->username ?? '—'))
            .$campo('Publicado em', e(($plano->enviado_em ?? now())->format('d/m/Y H:i'))));

        $html .= $secao('Indicadores',
            $campo('Participação atual', e($pct($plano->participacao_atual)).(! empty($ctx['previstos']) ? ' ('.e((string) $ctx['fizeram']).' de '.e((string) $ctx['previstos']).' previstos)' : ''))
            .$campo('Meta de participação', e($pct($plano->meta_participacao)).' (meta institucional)')
            .$campo('Alunos com desempenho esperado', e($pct($plano->esperado_atual)).' (alcançaram o mínimo esperado para o período em que estão)')
            .$campo('Meta de alunos com desempenho esperado', e($pct($plano->meta_esperado)))
            .$campo('Próxima avaliação', e($plano->data_proxima_avaliacao?->format('d/m/Y') ?? 'Não informada')));

        $avaliacoes = $plano->avaliacoesDoRecorte();
        if ($avaliacoes !== []) {
            $itens = collect($avaliacoes)->map(fn ($a) => '<li>'.e($a['nome']).' <span style="color:#64748b">#'.e((string) $a['codigo']).(! empty($a['data']) ? ' · '.e(\Illuminate\Support\Carbon::parse($a['data'])->format('d/m/Y')) : '').'</span>'
                .' — <a href="'.e(route('avaliacoes.bi', $a['codigo'])).'" style="color:#047857">abrir a avaliação</a></li>')->implode('');
            $html .= '<h2 style="font-size:16px;color:#0f172a;margin:22px 0 6px">Avaliações do recorte</h2><ul style="font-size:14px;color:#1e293b;padding-left:18px">'.$itens.'</ul>';
        }

        if (! empty($ctx['linhas'])) {
            $linhas = collect($ctx['linhas'])->map(fn ($l) => '<tr><td style="padding:4px 10px;border-bottom:1px solid #e2e8f0'.(! empty($l['destaque']) ? ';background:#ecfdf5;font-weight:bold' : '').'">'.e((string) $l['rotulo']).'</td>'
                .'<td style="padding:4px 10px;border-bottom:1px solid #e2e8f0;white-space:nowrap;font-weight:bold">'.e((string) $l['valor']).'</td>'
                .'<td style="padding:4px 10px;border-bottom:1px solid #e2e8f0;color:#64748b">'.e((string) ($l['detalhe'] ?? '')).'</td></tr>')->implode('');
            $html .= '<h2 style="font-size:16px;color:#0f172a;margin:22px 0 6px">O dado que originou o plano</h2><table style="width:100%;border-collapse:collapse;font-size:14px">'.$linhas.'</table>';
        }

        $html .= $secao('Leitura do dado',
            $campo('Recorte que merece atenção', $texto($plano->recorte))
            .$campo('Resultado a enfrentar', $texto($plano->resultado))
            .$campo('Competências, objetivos de aprendizagem ou níveis cognitivos com fragilidade', $texto($plano->fragilidades))
            .$campo('Dados que sustentam a escolha', $texto($plano->evidencias)));

        $causas = '';
        foreach (($plano->causas ?? []) as $chave => $causa) {
            $causas .= $campo('Causa possível · '.(PlanoAcao::DIMENSOES[$chave] ?? $chave), $texto($causa));
        }
        $porques = collect($plano->porques ?? [])->map(fn ($p, $i) => ($i + 1).'. '.e((string) $p))->implode('<br>');
        $html .= $secao('Causas',
            $causas
            .$campo('Causa priorizada', $texto($plano->causa_priorizada).($plano->pontuacao() !== null ? '<br><span style="color:#64748b">Impacto '.(int) $plano->nota_impacto.' × Evidência '.(int) $plano->nota_evidencia.' × Governabilidade '.(int) $plano->nota_governabilidade.' = '.$plano->pontuacao().' de 27</span>' : ''))
            .($porques !== '' ? $campo('Aprofundamento (5 Porquês)', $porques) : '')
            .$campo('Causa-raiz acionável', $texto($plano->causa_raiz)));

        $html .= '<h2 style="font-size:16px;color:#0f172a;margin:22px 0 6px">Ações pedagógicas</h2>';
        if ($plano->acoes->isEmpty()) {
            $html .= '<p style="font-size:14px;color:#64748b"><em>Nenhuma ação cadastrada.</em></p>';
        }
        foreach ($plano->acoes as $i => $acao) {
            /** @var PlanoAcaoAcao $acao */
            $html .= '<div style="border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;margin:0 0 10px"><p style="font-size:15px;font-weight:bold;color:#0f172a;margin:6px 0">'.($i + 1).'. '.e($acao->descricao).'</p>'
                .'<table style="width:100%;border-collapse:collapse;font-size:14px">'
                .$campo('Como será executada', $texto($acao->execucao))
                .$campo('Responsável', $texto($acao->responsavel))
                .$campo('Prazo', e($acao->prazo?->format('d/m/Y') ?? 'Não informado'))
                .$campo('Como será verificada a execução e os sinais de aprendizagem', $texto($acao->verificacao))
                .'</table></div>';
        }

        $lacunas = PlanoAcaoChecagem::lacunas($plano);
        $alertas = PlanoAcaoChecagem::alertas($plano);
        if ($lacunas !== [] || $alertas !== []) {
            $itens = collect([...array_column($lacunas, 'mensagem'), ...$alertas])->map(fn ($m) => '<li>'.e($m).'</li>')->implode('');
            $html .= '<h2 style="font-size:16px;color:#92400e;margin:22px 0 6px">Pontos de atenção</h2><ul style="font-size:14px;color:#92400e;padding-left:18px">'.$itens.'</ul>';
        }

        $html .= '<p style="margin-top:24px"><a href="'.e(route('colaborador.planos.show', $plano)).'" style="color:#047857;font-weight:bold;font-size:14px">Acompanhar o plano no sistema</a></p>'
            .'<p style="font-size:12px;color:#64748b">Você recebeu este e-mail porque o seu endereço está na lista de avisos de plano de ação do '.e($site).' (Configurações).</p>';

        return $this->tipografia($html);
    }

    private function tipografia(string $html): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:760px;margin:0 auto;color:#1e293b">'.$html.'</div>';
    }

    private function enviar(string $para, string $assunto, string $corpo, int $planoId): void
    {
        try {
            $this->remetente->enviar($para, $assunto, $corpo);
        } catch (\Throwable $e) {
            Log::warning('E-mail do plano de ação não enviado: '.$e->getMessage(), ['plano' => $planoId, 'para' => $para]);
        }
    }

    /** @return Collection<int, Admin> */
    private function coordenadoresDe(PlanoAcao $plano): Collection
    {
        return Admin::coordenadores()->whereNotNull('email')->where('email', '!=', '')->get()
            ->filter(fn (Admin $c) => NomeCurso::estaEm($plano->curso, $c->cursos()))
            ->values();
    }
}
