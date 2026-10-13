<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Avaliacao;
use App\Models\Notificacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoAnexo;
use App\Models\PlanoAcaoEvento;
use App\Support\AtividadeLogger;
use App\Support\NomeCurso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tudo que muda um plano de ação: criar e editar o rascunho, PUBLICAR, o acompanhamento das ações, o encerramento. Cada mudança
 * de estado grava um evento (o histórico do plano) e a auditoria do sistema.
 *
 * Não há aprovação. O fluxo:
 *
 *   rascunho ──publicar──▶ em_execucao ──encerrar──▶ concluido
 *                                      └──cancelar──▶ cancelado
 *
 * Ao publicar, os e-mails definidos em Configurações recebem o plano completo (PlanoAcaoEmailService). O colaborador só acompanha
 * (lê, comenta, exporta). Quem pode o quê é decidido pelos controllers (perfil e curso); aqui só vale a ordem das etapas — uma
 * transição fora do fluxo lança \DomainException.
 */
class PlanoAcaoService
{
    public function __construct(private readonly PlanoAcaoEmailService $email) {}

    // ---------------------------------------------------------------------------------------------------------------
    // Rascunho
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $origem  saída de PlanoAcaoOrigemService::montar() (recalculada no servidor)
     * @param  array<string, mixed>  $dados  o conteúdo preenchido (ver preencher())
     */
    public function criar(Admin $autor, array $origem, array $dados): PlanoAcao
    {
        $ind = $origem['indicadores'] ?? null;

        return DB::transaction(function () use ($autor, $origem, $dados, $ind) {
            $plano = new PlanoAcao([
                'admin_id' => $autor->id,
                'curso' => $origem['curso'],
                'periodo_letivo' => (string) ($origem['periodo_letivo'] ?? ''),
                'categoria_id' => $origem['categoria_id'] ?? null,
                'avaliacao_codigo' => $origem['avaliacao_codigo'] ?? null,
                'origem_visual' => $origem['visual'],
                'origem_item' => $origem['item'] ?? null,
                'origem_rotulo' => $origem['rotulo'],
                'contexto' => [
                    'linhas' => $origem['linhas'] ?? [],
                    'avaliacoes' => $origem['avaliacoes'] ?? [],
                    'categoria' => $origem['categoria_nome'] ?? null,
                    'categoria_automatica' => (bool) ($origem['categoria_automatica'] ?? false),
                    'recorte_indicadores' => $ind['recorte'] ?? null,
                    'corte' => $ind['corte'] ?? null,
                    'criterio' => $ind['criterio'] ?? 'corte',
                    'previstos' => $ind['previstos'] ?? null,
                    'fizeram' => $ind['fizeram'] ?? null,
                    'mistura' => (bool) ($ind['mistura'] ?? false),
                ],
                // A foto dos indicadores: a linha de base do plano. Não muda depois (o painel muda; o plano lembra
                // de onde partiu).
                'participacao_atual' => $ind['participacao'] ?? null,
                'meta_participacao' => $ind['meta_participacao'] ?? null,
                'proficiencia_atual' => $ind['proficiencia'] ?? null,
                'status' => PlanoAcao::RASCUNHO,
            ]);
            $this->preencher($plano, $dados);
            $plano->save();
            $this->sincronizarAcoes($plano, $dados['acoes'] ?? []);

            $this->registrar($plano, $autor, PlanoAcaoEvento::CRIADO, null, ['origem' => $plano->origem_rotulo]);
            AtividadeLogger::registrar('plano_acao.criado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'origem' => $plano->origem_rotulo]);

            return $plano;
        });
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(PlanoAcao $plano, array $dados): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO], 'Este plano já foi publicado e não pode mais ser editado.');

        return DB::transaction(function () use ($plano, $dados) {
            $this->preencher($plano, $dados);
            $plano->save();
            $this->sincronizarAcoes($plano, $dados['acoes'] ?? []);

            return $plano->unsetRelation('acoes');
        });
    }

    /** Copia um plano já encerrado (ou recusado) como NOVO rascunho, com indicadores recalculados a partir de `$origem`. */
    public function duplicar(PlanoAcao $modelo, Admin $autor, array $origem): PlanoAcao
    {
        $dados = $modelo->only([
            'recorte', 'resultado', 'fragilidades', 'evidencias', 'causas', 'causa_priorizada', 'nota_impacto', 'nota_evidencia',
            'nota_governabilidade', 'porques', 'causa_raiz', 'meta_proficiencia',
        ]);
        // Prazo e situação não se herdam: o novo plano é de outro ciclo.
        $dados['acoes'] = $modelo->acoes->reject(fn (PlanoAcaoAcao $a) => $a->status === PlanoAcaoAcao::CANCELADA)
            ->map(fn (PlanoAcaoAcao $a) => ['descricao' => $a->descricao, 'execucao' => $a->execucao, 'responsavel' => $a->responsavel, 'verificacao' => $a->verificacao])
            ->values()->all();

        $plano = $this->criar($autor, $origem, $dados);
        $this->registrar($plano, $autor, PlanoAcaoEvento::COMENTARIO, "Criado a partir do plano #{$modelo->id}.");

        return $plano;
    }

    /**
     * Planos ainda vivos (rascunho ou em execução) sobre o MESMO recorte e o mesmo visual/item — para avisar
     * o coordenador antes de abrir um plano repetido. Só informa: nada é barrado.
     *
     * @return Collection<int, PlanoAcao>
     */
    public function semelhantes(string $curso, string $periodoLetivo, ?int $categoriaId, string $visual, ?string $item): Collection
    {
        return PlanoAcao::dosCursos([$curso])
            ->whereIn('status', [PlanoAcao::RASCUNHO, PlanoAcao::EM_EXECUCAO])
            ->where('periodo_letivo', $periodoLetivo)
            ->where('origem_visual', $visual)
            ->when($categoriaId !== null, fn ($q) => $q->where('categoria_id', $categoriaId), fn ($q) => $q->whereNull('categoria_id'))
            ->when($item !== null, fn ($q) => $q->where('origem_item', $item), fn ($q) => $q->whereNull('origem_item'))
            ->orderByDesc('id')
            ->get();
    }

    /** Só rascunho some de verdade: o que já foi enviado fica no histórico (cancele em vez de apagar). */
    public function excluir(PlanoAcao $plano): void
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO], 'Só um rascunho pode ser excluído.');
        AtividadeLogger::registrar('plano_acao.excluido', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'origem' => $plano->origem_rotulo]);
        $plano->delete();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Publicação
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Publica o plano: sai do rascunho e entra em execução, e os e-mails definidos em Configurações recebem o plano completo.
     * Nenhuma etapa é obrigatória: o que estiver em branco aparece como lacuna (PlanoAcaoChecagem::lacunas) para quem acompanha,
     * mas não impede a publicação.
     */
    public function publicar(PlanoAcao $plano, Admin $autor): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::RASCUNHO], 'Este plano já foi publicado.');

        $plano->forceFill(['status' => PlanoAcao::EM_EXECUCAO, 'envios' => 1, 'enviado_em' => now()])->save();

        $this->registrar($plano, $autor, PlanoAcaoEvento::PUBLICADO);
        AtividadeLogger::registrar('plano_acao.publicado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);
        $this->email->novoPlano($plano->fresh(['acoes', 'autor']));

        return $plano;
    }

    /** Comentário solto no plano. Quando vem de quem acompanha (colaborador, administrador), o coordenador é avisado. */
    public function comentar(PlanoAcao $plano, Admin $autor, string $texto): PlanoAcaoEvento
    {
        $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::COMENTARIO, trim($texto));

        if (! $autor->ehCoordenador()) {
            $this->avisar($plano, 'plano', "Novo comentário no plano: {$plano->origem_rotulo}", Str::limit(trim($texto), 280), "plano:{$plano->id}:comentario:{$evento->id}");
            $this->email->comentarioParaOCoordenador($plano, trim($texto));
        }

        return $evento;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Acompanhamento
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Atualiza uma ação de um plano em execução: a situação, o prazo e/ou uma nota de andamento.
     *  - concluir exige a nota (o que foi feito e a evidência);
     *  - mudar o prazo exige a nota (por que foi reprogramado) e não pode ir para o passado;
     *  - sem mudar nada, a nota vira um registro de andamento.
     *
     * Evidências (link e/ou arquivo) podem acompanhar qualquer atualização — e, sozinhas, já valem como um registro de andamento.
     *
     * @param  array{status?: ?string, prazo?: ?string, nota?: ?string, link_url?: ?string, link_titulo?: ?string, arquivo?: ?UploadedFile}  $dados
     */
    public function atualizarAcao(PlanoAcao $plano, PlanoAcaoAcao $acao, Admin $autor, array $dados): PlanoAcaoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::EM_EXECUCAO], 'As ações só são acompanhadas enquanto o plano está em execução.');

        $nota = trim((string) ($dados['nota'] ?? ''));
        $novoStatus = ($dados['status'] ?? '') !== '' ? (string) $dados['status'] : $acao->status;
        $novoPrazo = ($dados['prazo'] ?? '') !== '' ? Carbon::parse((string) $dados['prazo'])->startOfDay() : $acao->prazo;

        if (! isset(PlanoAcaoAcao::STATUS[$novoStatus])) {
            throw ValidationException::withMessages(['status' => ['Situação inválida.']]);
        }
        $mudouStatus = $novoStatus !== $acao->status;
        $mudouPrazo = ($novoPrazo?->toDateString()) !== ($acao->prazo?->toDateString());

        if (! $mudouStatus && ! $mudouPrazo && $nota === '' && ! self::temAnexo($dados)) {
            throw ValidationException::withMessages(['nota' => ['Informe a nova situação, um novo prazo, escreva uma nota de andamento ou anexe uma evidência.']]);
        }
        if ($mudouStatus && $novoStatus === PlanoAcaoAcao::CONCLUIDA && mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota' => ['Para concluir, descreva o que foi feito e a evidência (mínimo de 10 caracteres).']]);
        }
        if ($mudouPrazo && mb_strlen($nota) < 10) {
            throw ValidationException::withMessages(['nota' => ['Explique por que o prazo foi reprogramado (mínimo de 10 caracteres).']]);
        }
        if ($mudouPrazo && $novoPrazo->isBefore(today())) {
            throw ValidationException::withMessages(['prazo' => ['O novo prazo não pode estar no passado.']]);
        }

        DB::transaction(function () use ($plano, $acao, $autor, $nota, $novoStatus, $novoPrazo, $mudouStatus, $mudouPrazo, $dados) {
            $de = ['status' => $acao->status, 'prazo' => $acao->prazo?->toDateString()];
            $acao->forceFill([
                'status' => $novoStatus,
                'prazo' => $novoPrazo,
                'concluida_em' => $novoStatus === PlanoAcaoAcao::CONCLUIDA ? ($acao->concluida_em ?? now()) : null,
            ])->save();

            $evento = null;
            if ($mudouStatus) {
                $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::ACAO_STATUS, $nota !== '' ? $nota : null, ['de' => $de['status'], 'para' => $novoStatus], $acao->id);
            }
            if ($mudouPrazo) {
                $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::PRAZO, $nota, ['de' => $de['prazo'], 'para' => $novoPrazo->toDateString()], $acao->id);
            }
            if (! $mudouStatus && ! $mudouPrazo) {
                $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::ANDAMENTO, $nota !== '' ? $nota : null, null, $acao->id);
            }

            $this->guardarAnexos($plano, $acao->id, $evento, $autor, $dados);
        });

        return $acao;
    }

    /** Fecha o plano: todas as ações concluídas (ou canceladas) e uma síntese do que foi feito e aprendido. */
    public function encerrar(PlanoAcao $plano, Admin $autor, string $conclusao, array $anexos = []): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::EM_EXECUCAO], 'Só um plano em execução pode ser encerrado.');

        $conclusao = trim($conclusao);
        if (mb_strlen($conclusao) < 10) {
            throw ValidationException::withMessages(['conclusao' => ['Escreva a síntese do que foi feito e aprendido (mínimo de 10 caracteres).']]);
        }
        if ($plano->acoes()->whereIn('status', [PlanoAcaoAcao::NAO_INICIADA, PlanoAcaoAcao::EM_ANDAMENTO])->exists()) {
            throw ValidationException::withMessages(['conclusao' => ['Ainda há ações abertas: conclua ou cancele todas antes de encerrar o plano.']]);
        }

        $plano->forceFill(['status' => PlanoAcao::CONCLUIDO, 'encerrado_em' => now(), 'conclusao' => $conclusao])->save();
        $evento = $this->registrar($plano, $autor, PlanoAcaoEvento::ENCERRADO, $conclusao);
        $this->guardarAnexos($plano, null, $evento, $autor, $anexos);
        AtividadeLogger::registrar('plano_acao.encerrado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);

        return $plano;
    }

    /** Cancela um plano em execução (com o motivo). */
    public function cancelar(PlanoAcao $plano, Admin $autor, string $motivo): PlanoAcao
    {
        $this->exigirEstado($plano, [PlanoAcao::EM_EXECUCAO], 'Só um plano em execução pode ser cancelado.');

        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages(['motivo' => ['Explique o motivo do cancelamento (mínimo de 10 caracteres).']]);
        }

        $plano->forceFill(['status' => PlanoAcao::CANCELADO, 'encerrado_em' => now()])->save();
        $this->registrar($plano, $autor, PlanoAcaoEvento::CANCELADO, $motivo);
        AtividadeLogger::registrar('plano_acao.cancelado', 'PlanoAcao', $plano->id, ['curso' => $plano->curso]);

        return $plano;
    }

    /**
    // ---------------------------------------------------------------------------------------------------------------
    // Só o administrador: reverter para rascunho ou excluir de vez
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Devolve um plano publicado (em execução, concluído ou cancelado) ao estado de rascunho, para o coordenador corrigir e
     * publicar de novo. O histórico (log) e as evidências ficam; o coordenador é avisado. O motivo é obrigatório.
     */
    public function reverterParaRascunho(PlanoAcao $plano, Admin $admin, string $motivo): PlanoAcao
    {
        $this->exigirAdministrador($admin);
        $this->exigirEstado($plano, [PlanoAcao::EM_EXECUCAO, PlanoAcao::CONCLUIDO, PlanoAcao::CANCELADO], 'Este plano já é um rascunho.');
        $motivo = $this->motivoDoAdministrador($motivo, 'da reversão');
        $anterior = $plano->status;

        DB::transaction(function () use ($plano, $admin, $motivo, $anterior) {
            $plano->forceFill(['status' => PlanoAcao::RASCUNHO, 'enviado_em' => null, 'encerrado_em' => null, 'conclusao' => null])->save();
            $this->registrar($plano, $admin, PlanoAcaoEvento::REVERTIDO, $motivo, ['de' => $anterior]);
        });

        AtividadeLogger::registrar('plano_acao.revertido', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'de' => $anterior, 'motivo' => $motivo]);
        $this->avisar($plano, 'plano', "Plano devolvido para rascunho: {$plano->origem_rotulo}", Str::limit($motivo, 280), "plano:{$plano->id}:revertido:".now()->timestamp);

        return $plano;
    }

    /**
     * Apaga o plano de vez: ações, histórico, evidências (inclusive os arquivos do disco) e avisos. Não tem volta; por isso o motivo
     * é obrigatório e a auditoria guarda uma foto do que existia (só rótulos e contagens, nunca o texto do plano).
     */
    public function excluirDefinitivamente(PlanoAcao $plano, Admin $admin, string $motivo): void
    {
        $this->exigirAdministrador($admin);
        $motivo = $this->motivoDoAdministrador($motivo, 'da exclusão');

        $foto = [
            'curso' => $plano->curso, 'origem' => $plano->origem_rotulo, 'periodo_letivo' => $plano->periodo_letivo, 'status' => $plano->status,
            'autor' => $plano->autor?->username, 'acoes' => $plano->acoes()->count(), 'eventos' => $plano->eventos()->count(), 'motivo' => $motivo,
        ];

        DB::transaction(function () use ($plano) {
            Notificacao::where('chave', 'like', "plano:{$plano->id}:%")->delete();
            PlanoAcaoAnexo::where('plano_id', $plano->id)->delete();
            $plano->delete();
        });

        // O disco só depois do banco: se a transação falhar, os arquivos continuam onde estão.
        Storage::disk(PlanoAcaoAnexo::DISCO)->deleteDirectory(PlanoAcaoAnexo::PASTA.'/'.$plano->id);
        AtividadeLogger::registrar('plano_acao.excluido_pelo_administrador', 'PlanoAcao', $plano->id, $foto);
    }

    private function exigirAdministrador(Admin $admin): void
    {
        if (! $admin->ehAdministrador()) {
            throw new \DomainException('Só o administrador pode fazer isso.');
        }
    }

    private function motivoDoAdministrador(string $motivo, string $de): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages(['motivo' => ["Explique o motivo {$de} (mínimo de 10 caracteres)."]]);
        }

        return $motivo;
    }

     * Dessas avaliações, as que o usuário pode abrir no Dashboard (que mostra dados de alunos): o coordenador, as dos cursos
     * dele; o administrador e o colaborador, todas.
     *
     * @param  array<int, int>  $codigos
     * @return array<int, int>
     */
    public static function avaliacoesAcessiveis(Admin $usuario, array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        if ($usuario->ehCoordenador()) {
            return Avaliacao::visivelPara($usuario)->whereIn('codigo', $codigos)->pluck('codigo')->map(fn ($c) => (int) $c)->all();
        }

        // Administrador e colaborador (que já têm acesso às planilhas importadas) abrem qualquer avaliação.
        return $usuario->ehAdministrador() || $usuario->ehColaborador() ? Avaliacao::whereIn('codigo', $codigos)->pluck('codigo')->map(fn ($c) => (int) $c)->all() : [];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Contador do menu
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Quantos planos em execução têm ação com o prazo vencido (o número do menu). Quem acompanha vê os de todos os cursos; o
     * coordenador, os dos cursos dele. Roda em TODA página: uma consulta só, e a comparação de curso em PHP. Zero se a tabela
     * ainda não existe.
     */
    public static function comAcaoAtrasada(Admin $usuario): int
    {
        try {
            $planos = PlanoAcaoAcao::query()
                ->join('planos_acao as p', 'p.id', '=', 'plano_acao_acoes.plano_id')
                ->where('p.status', PlanoAcao::EM_EXECUCAO)
                ->whereIn('plano_acao_acoes.status', [PlanoAcaoAcao::NAO_INICIADA, PlanoAcaoAcao::EM_ANDAMENTO])
                ->whereDate('plano_acao_acoes.prazo', '<', today())
                ->select('p.id', 'p.curso')->distinct()->get();

            if ($usuario->ehCoordenador()) {
                $cursos = $usuario->cursos();
                $planos = $planos->filter(fn ($p) => NomeCurso::estaEm($p->curso, $cursos));
            }

            return $planos->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $dados */
    private static function temAnexo(array $dados): bool
    {
        return trim((string) ($dados['link_url'] ?? '')) !== '' || ($dados['arquivo'] ?? null) instanceof UploadedFile;
    }

    /**
     * Guarda as evidências informadas (um link e/ou um arquivo) ligadas ao evento do histórico. O arquivo vai para o disco
     * PRIVADO (planos/{plano}/...): só sai pela rota autenticada, nunca por URL pública.
     *
     * @param  array<string, mixed>  $dados  link_url, link_titulo, arquivo
     */
    private function guardarAnexos(PlanoAcao $plano, ?int $acaoId, ?PlanoAcaoEvento $evento, Admin $autor, array $dados): void
    {
        $base = ['plano_id' => $plano->id, 'acao_id' => $acaoId, 'evento_id' => $evento?->id, 'admin_id' => $autor->id];
        $titulo = trim((string) ($dados['link_titulo'] ?? ''));

        $url = trim((string) ($dados['link_url'] ?? ''));
        if ($url !== '') {
            PlanoAcaoAnexo::create([...$base, 'tipo' => PlanoAcaoAnexo::LINK, 'url' => $url, 'titulo' => $titulo !== '' ? Str::limit($titulo, 190, '') : Str::limit((string) (parse_url($url, PHP_URL_HOST) ?: $url), 190, '')]);
        }

        $arquivo = $dados['arquivo'] ?? null;
        if ($arquivo instanceof UploadedFile) {
            $nome = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $arquivo->getClientOriginalName()) ?? 'arquivo');
            PlanoAcaoAnexo::create([
                ...$base,
                'tipo' => PlanoAcaoAnexo::ARQUIVO,
                'titulo' => $titulo !== '' && $url === '' ? Str::limit($titulo, 190, '') : Str::limit($nome, 190, ''),
                'caminho' => $arquivo->store(PlanoAcaoAnexo::PASTA.'/'.$plano->id, PlanoAcaoAnexo::DISCO),
                'nome_original' => Str::limit($nome, 250, ''),
                'mime' => $arquivo->getMimeType(),
                'tamanho' => $arquivo->getSize(),
            ]);
        }

        if ($url !== '' || $arquivo instanceof UploadedFile) {
            AtividadeLogger::registrar('plano_acao.evidencia_anexada', 'PlanoAcao', $plano->id, ['curso' => $plano->curso, 'link' => $url !== '', 'arquivo' => $arquivo instanceof UploadedFile]);
        }
    }

    /** @param array<int, string> $permitidos */
    private function exigirEstado(PlanoAcao $plano, array $permitidos, string $mensagem): void
    {
        if (! in_array($plano->status, $permitidos, true)) {
            throw new \DomainException($mensagem);
        }
    }

    /**
     * Copia o conteúdo preenchido para o plano (sem salvar). Textos vazios viram null; notas só de 1 a 3; só as dimensões
     * de Ishikawa conhecidas; até 5 porquês.
     *
     * @param  array<string, mixed>  $dados
     */
    private function preencher(PlanoAcao $plano, array $dados): void
    {
        $texto = fn ($v) => ($t = trim((string) $v)) === '' ? null : $t;

        foreach (['recorte', 'resultado', 'fragilidades', 'evidencias', 'causa_priorizada', 'causa_raiz'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $plano->{$campo} = $texto($dados[$campo]);
            }
        }

        foreach (['nota_impacto', 'nota_evidencia', 'nota_governabilidade'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $nota = (int) $dados[$campo];
                $plano->{$campo} = $nota >= 1 && $nota <= 3 ? $nota : null;
            }
        }

        // A meta de participação é a institucional (vem do painel, não se edita); só a de proficiência é pactuada pelo coordenador.
        if (array_key_exists('meta_proficiencia', $dados)) {
            $plano->meta_proficiencia = ($v = str_replace(',', '.', trim((string) $dados['meta_proficiencia']))) === '' ? null : round((float) $v, 1);
        }

        if (array_key_exists('data_proxima_avaliacao', $dados)) {
            $plano->data_proxima_avaliacao = $texto($dados['data_proxima_avaliacao']);
        }

        if (array_key_exists('causas', $dados)) {
            $causas = [];
            foreach (PlanoAcao::DIMENSOES as $chave => $rotulo) {
                if (($t = $texto($dados['causas'][$chave] ?? null)) !== null) {
                    $causas[$chave] = $t;
                }
            }
            $plano->causas = $causas === [] ? null : $causas;
        }

        if (array_key_exists('porques', $dados)) {
            $porques = array_values(array_filter(array_map($texto, array_slice((array) $dados['porques'], 0, 5))));
            $plano->porques = $porques === [] ? null : $porques;
        }
    }

    /**
     * Deixa as ações do plano exatamente como informado: linha com `id` atualiza a existente, sem `id` cria, e a que sumiu
     * da lista é removida (só enquanto o plano é editável: depois de aprovado, as ações não saem).
     *
     * @param  array<int, array<string, mixed>>  $linhas
     */
    private function sincronizarAcoes(PlanoAcao $plano, array $linhas): void
    {
        $existentes = $plano->acoes()->get()->keyBy('id');
        $mantidos = [];
        $ordem = 0;

        foreach ($linhas as $linha) {
            $descricao = trim((string) ($linha['descricao'] ?? ''));
            $vazia = $descricao === '' && trim((string) ($linha['execucao'] ?? '')) === '' && trim((string) ($linha['responsavel'] ?? '')) === '' && trim((string) ($linha['verificacao'] ?? '')) === '';
            if ($vazia) {
                continue;
            }

            $valores = [
                'ordem' => ++$ordem,
                'descricao' => $descricao,
                'execucao' => ($t = trim((string) ($linha['execucao'] ?? ''))) === '' ? null : $t,
                'responsavel' => ($t = trim((string) ($linha['responsavel'] ?? ''))) === '' ? null : mb_substr($t, 0, 150),
                'prazo' => ($t = trim((string) ($linha['prazo'] ?? ''))) === '' ? null : $t,
                'verificacao' => ($t = trim((string) ($linha['verificacao'] ?? ''))) === '' ? null : $t,
            ];

            $id = (int) ($linha['id'] ?? 0);
            if ($id > 0 && $existentes->has($id)) {
                $existentes[$id]->update($valores);
                $mantidos[] = $id;
            } else {
                $mantidos[] = $plano->acoes()->create($valores)->id;
            }
        }

        $existentes->keys()->diff($mantidos)->each(fn ($id) => $existentes[$id]->delete());
    }

    /** @param array<string, mixed>|null $dados */
    private function registrar(PlanoAcao $plano, ?Admin $quem, string $tipo, ?string $texto = null, ?array $dados = null, ?int $acaoId = null): PlanoAcaoEvento
    {
        return PlanoAcaoEvento::create([
            'plano_id' => $plano->id,
            'acao_id' => $acaoId,
            'admin_id' => $quem?->id,
            'tipo' => $tipo,
            'texto' => $texto,
            'dados' => $dados,
        ]);
    }

    /**
     * Aviso (o sino) para todo coordenador do curso do plano; idempotente pela chave. `$reabrir = false` (lembretes
     * diários) só cria o aviso na primeira vez: rodar de novo não volta para "não lido" o que ele já leu.
     *
     * @return int quantos avisos foram criados ou atualizados
     */
    public function avisar(PlanoAcao $plano, string $tipo, string $titulo, string $texto, string $chave, bool $reabrir = true): int
    {
        $url = route('coordenador.planos.show', $plano);
        $total = 0;

        foreach (Admin::coordenadores()->get() as $coordenador) {
            if (! NomeCurso::estaEm($plano->curso, $coordenador->cursos())) {
                continue;
            }

            $chaves = ['admin_id' => $coordenador->id, 'chave' => $chave];
            $valores = ['tipo' => $tipo, 'titulo' => Str::limit($titulo, 190, '…'), 'texto' => $texto, 'url' => $url, 'lida_em' => null];
            $aviso = $reabrir ? Notificacao::updateOrCreate($chaves, $valores) : Notificacao::firstOrCreate($chaves, $valores);
            $total += $reabrir || $aviso->wasRecentlyCreated ? 1 : 0;
        }

        return $total;
    }
}
