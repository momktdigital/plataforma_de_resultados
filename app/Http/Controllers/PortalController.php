<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultaResultadoRequest;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Configuracao;
use App\Models\Questao;
use App\Models\VerificacaoEmail;
use App\Services\Portal\AnaliseConsolidadaService;
use App\Services\Portal\CaptchaVerifier;
use App\Services\Portal\ExplicacaoVisualService;
use App\Services\Portal\InsightService;
use App\Services\Portal\RateLimit2faService;
use App\Services\Portal\RelatorioAlunoService;
use App\Services\Portal\ResultadoConsultaService;
use App\Services\Portal\SmtpEmailSender;
use App\Services\Visualizacoes\VisualizacaoConfigService;
use App\Support\Anulacao;
use App\Support\LeituraDaProva;
use App\Support\LeituraMetaPeriodo;
use App\Support\PeriodoCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Portal público de consulta de resultados — porta index.php + api/consulta.php
 * + api/verify_2fa.php + api/resend_2fa.php para cá. Diferença de fundo:
 * aqui os resultados vêm do schema novo (avaliacoes/questoes/respostas/
 * resultado_metricas), não do JSON por aluno de `resultados`/`gabaritos`.
 *
 * Fluxo replicado fielmente do legado (sem sessão de autenticação — o CPF
 * circula pelos três passos via campo oculto, igual ao app legado):
 * consultar (CPF + Data Nascimento [+ CAPTCHA]) → se 2FA ativo, envia
 * código por e-mail e pede verificação → resultados.
 */
class PortalController extends Controller
{
    private const ASSUNTO_PADRAO = 'Seu código de acesso aos resultados';

    private const CORPO_PADRAO = 'Olá [NOME_DO_ALUNO],<br><br>Seu código de verificação é: <b>[CODIGO]</b><br><br>Este código expira em 10 minutos.<br><br>Se você não solicitou este acesso, por favor ignore este e-mail.';

    /** Janelas de cooldown entre reenvios, em minutos (índice = vezes já reenviado). */
    private const ESPERAS_REENVIO = [1, 2, 5, 10];

    /**
     * Prova de que o PRIMEIRO fator (CPF + data de nascimento) foi acertado nesta sessão — sem ela, `verificar` e
     * `reenviar` não fazem nada. Antes elas confiavam num CPF vindo de um campo oculto do formulário: quem soubesse
     * só o CPF de um aluno conseguia gastar as 3 tentativas do código dele (travando o acesso) ou disparar e-mails
     * de reenvio para ele.
     */
    private const SESSAO_PRE_AUTH = 'portal_pre_auth';

    private const PRE_AUTH_MINUTOS = 15;

    /** Consultas com CPF/data errados toleradas, por CPF (de qualquer IP), antes de bloquear esse CPF por 1 hora. */
    private const MAX_FALHAS_POR_CPF = 10;

    private const BLOQUEIO_CPF_SEGUNDOS = 3600;

    public function mostrarConsulta(): View
    {
        return view('portal.consulta', $this->configuracaoCaptcha());
    }

    public function consultar(
        ConsultaResultadoRequest $request,
        CaptchaVerifier $captcha,
        SmtpEmailSender $mailer,
    ): View|RedirectResponse {
        $dados = $request->validated();
        $cpf = $dados['cpf'];

        // O limite por IP (throttle da rota) não segura quem tenta a data de nascimento de UM CPF a partir de muitos
        // IPs. Este é por CPF, de onde vier, e também conta CPF que nem existe (a resposta é a mesma nos dois casos).
        $chaveCpf = 'portal-consulta:'.$cpf;
        if (RateLimiter::tooManyAttempts($chaveCpf, self::MAX_FALHAS_POR_CPF)) {
            $minutos = (int) ceil(RateLimiter::availableIn($chaveCpf) / 60);

            return back()
                ->withErrors(['cpf' => "Muitas tentativas para este CPF. Tente novamente em {$minutos} minuto(s)."])
                ->withInput();
        }

        if ($erro = $this->validarCaptcha($request, $captcha)) {
            return back()->withErrors(['captcha' => $erro])->withInput();
        }

        $dataNascimento = Carbon::createFromFormat('d/m/Y', $dados['data_nascimento'])->format('Y-m-d');

        $aluno = Aluno::where('cpf', $cpf)->whereDate('data_nascimento', $dataNascimento)->first();

        if ($aluno === null) {
            RateLimiter::hit($chaveCpf, self::BLOQUEIO_CPF_SEGUNDOS);

            return back()
                ->withErrors(['cpf' => 'Nenhum aluno encontrado com este CPF e Data de Nascimento.'])
                ->withInput();
        }

        RateLimiter::clear($chaveCpf);

        if (Configuracao::valor('smtp_ativo', '0') === '1') {
            $emailDoCodigo = $aluno->emailParaCodigo();
            if ($emailDoCodigo === null) {
                $academico = Configuracao::valor('email_destino_2fa', 'pessoal') === 'academico';

                return back()
                    ->withErrors(['cpf' => $academico
                        ? 'O 2FA está ativo, mas não foi possível determinar o seu e-mail acadêmico. Contate a secretaria.'
                        : 'O 2FA está ativo, mas você não tem e-mail cadastrado. Contate a secretaria.'])
                    ->withInput();
            }

            $verificacao = VerificacaoEmail::where('cpf', $cpf)->latest('id')->first();

            if ($verificacao === null || $verificacao->expira_em->isPast()) {
                try {
                    $this->emitirCodigo($cpf, $aluno, $mailer);
                } catch (TransportExceptionInterface) {
                    return back()
                        ->withErrors(['cpf' => 'Erro ao enviar o e-mail de verificação. Tente novamente mais tarde.'])
                        ->withInput();
                }
            }

            // Primeiro fator aprovado: é só isso que libera `verificar` e `reenviar`.
            session([self::SESSAO_PRE_AUTH => [
                'aluno_id' => $aluno->id,
                'cpf' => $cpf,
                'ate' => Carbon::now()->addMinutes(self::PRE_AUTH_MINUTOS)->timestamp,
            ]]);

            return view('portal.verificar', ['emailOculto' => $this->ocultarEmail($emailDoCodigo)]);
        }

        return $this->autenticarEIrParaResultados($request, $aluno);
    }

    public function verificar(Request $request, RateLimit2faService $rateLimiter): View|RedirectResponse
    {
        $dados = $request->validate(['codigo' => ['required', 'string']]);

        $preAuth = $this->preAutenticacao();
        if ($preAuth === null) {
            return $this->primeiroFatorExpirado();
        }
        $cpf = $preAuth['cpf'];
        $ip = $request->ip();

        if ($rateLimiter->estaBloqueado($ip)) {
            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Muitas tentativas deste dispositivo. Tente novamente em 1 hora.']);
        }

        $verificacao = VerificacaoEmail::where('cpf', $cpf)->latest('id')->first();

        if ($verificacao === null) {
            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Nenhuma verificação pendente para este CPF.']);
        }

        if ($verificacao->expira_em->isPast()) {
            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Código expirado. Solicite um novo código.']);
        }

        if ($verificacao->tentativas_falhas >= 3) {
            $rateLimiter->registrarFalha($ip);

            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Muitas tentativas falhas. Bloqueado por 1 hora.']);
        }

        // hash_equals (não !==): compara em tempo constante — evita que a
        // duração da resposta vaze quantos caracteres do código já acertou.
        // Compara os HASHES: o código em si nunca fica gravado (ver VerificacaoEmail::hashDoCodigo()).
        if (! hash_equals($verificacao->codigo, VerificacaoEmail::hashDoCodigo($cpf, trim($dados['codigo'])))) {
            $verificacao->increment('tentativas_falhas');
            $rateLimiter->registrarFalha($ip);

            $restantes = 3 - $verificacao->tentativas_falhas;

            if ($restantes <= 0) {
                return redirect()->route('portal.consulta')
                    ->withErrors(['cpf' => 'Código incorreto 3 vezes. Dispositivo bloqueado por 1h.']);
            }

            return view('portal.verificar', [
                'emailOculto' => null,
                'erro' => "Código incorreto. Você tem mais {$restantes} tentativa(s).",
            ]);
        }

        $verificacao->delete();
        $rateLimiter->resetar($ip);

        $aluno = Aluno::find($preAuth['aluno_id']);

        if ($aluno === null) {
            return redirect()->route('portal.consulta')->withErrors(['cpf' => 'Aluno não encontrado.']);
        }

        session()->forget(self::SESSAO_PRE_AUTH);

        return $this->autenticarEIrParaResultados($request, $aluno);
    }

    public function reenviar(SmtpEmailSender $mailer): View|RedirectResponse
    {
        $preAuth = $this->preAutenticacao();
        if ($preAuth === null) {
            return $this->primeiroFatorExpirado();
        }
        $cpf = $preAuth['cpf'];

        $verificacao = VerificacaoEmail::where('cpf', $cpf)->latest('id')->first();

        if ($verificacao === null) {
            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Nenhuma verificação pendente para este CPF. Tente fazer a consulta novamente.']);
        }

        $aluno = Aluno::find($preAuth['aluno_id']);

        $emailDoCodigo = $aluno?->emailParaCodigo();
        if ($aluno === null || $emailDoCodigo === null) {
            return redirect()->route('portal.consulta')->withErrors(['cpf' => 'Aluno ou e-mail não encontrado.']);
        }

        $indice = min($verificacao->vezes_reenviado, 3);
        $minutosEspera = $verificacao->ultimo_reenvio === null ? 1 : self::ESPERAS_REENVIO[$indice];
        $referencia = $verificacao->ultimo_reenvio ?? $verificacao->criado_em;
        $fimEspera = $referencia->copy()->addMinutes($minutosEspera);

        if (Carbon::now()->lt($fimEspera)) {
            $minutosRestantes = (int) ceil(Carbon::now()->diffInSeconds($fimEspera) / 60);

            return view('portal.verificar', [
                'emailOculto' => null,
                'erro' => "Aguarde {$minutosRestantes} minuto(s) para solicitar um novo código.",
            ]);
        }

        // Como só o hash fica gravado, o reenvio gera um código NOVO (e zera as tentativas dele).
        $codigo = sprintf('%06d', random_int(0, 999999));

        $verificacao->codigo = VerificacaoEmail::hashDoCodigo($cpf, $codigo);
        $verificacao->tentativas_falhas = 0;
        $verificacao->vezes_reenviado++;
        $verificacao->ultimo_reenvio = Carbon::now();
        $verificacao->expira_em = Carbon::now()->addMinutes(10);
        $verificacao->save();

        try {
            $mailer->enviar($emailDoCodigo, '[Reenvio] '.$this->montarTexto('subject', $aluno, $codigo), $this->montarTexto('body', $aluno, $codigo));
        } catch (TransportExceptionInterface) {
            return view('portal.verificar', [
                'emailOculto' => null,
                'erro' => 'Erro ao enviar o e-mail. Tente novamente.',
            ]);
        }

        return view('portal.verificar', [
            'emailOculto' => $this->ocultarEmail($emailDoCodigo),
            'status' => 'Código reenviado com sucesso.',
        ]);
    }

    /**
     * Pré-autenticação desta sessão (primeiro fator aprovado e ainda dentro da validade) ou null.
     *
     * @return array{aluno_id: int, cpf: string}|null
     */
    private function preAutenticacao(): ?array
    {
        $pre = session(self::SESSAO_PRE_AUTH);

        if (! is_array($pre) || ! isset($pre['aluno_id'], $pre['cpf'], $pre['ate']) || $pre['ate'] < Carbon::now()->timestamp) {
            return null;
        }

        return ['aluno_id' => (int) $pre['aluno_id'], 'cpf' => (string) $pre['cpf']];
    }

    private function primeiroFatorExpirado(): RedirectResponse
    {
        session()->forget(self::SESSAO_PRE_AUTH);

        return redirect()->route('portal.consulta')
            ->withErrors(['cpf' => 'Sua verificação expirou. Informe o CPF e a data de nascimento novamente.']);
    }

    /**
     * Consultas GET aos resultados exigem ter passado antes pelo CPF + Data de
     * Nascimento (e 2FA, se ativo) em consultar()/verificar(). Guardamos só o
     * id do aluno na sessão — nada de repassar isso por querystring, senão
     * qualquer um poderia adivinhar/alterar a URL e ver boletim alheio.
     */
    public function resultados(
        Request $request,
        ResultadoConsultaService $consultaService,
        RelatorioAlunoService $relatorioService,
        AnaliseConsolidadaService $analiseService,
        InsightService $insightService,
        ExplicacaoVisualService $explicacaoService,
    ): View|RedirectResponse {
        $aluno = $this->alunoAutenticado();

        if ($aluno === null) {
            return redirect()->route('portal.consulta');
        }

        return $this->renderizarResultados($aluno, $consultaService, $relatorioService, $analiseService, $insightService, $explicacaoService, $request);
    }

    /** Detalhe de uma única avaliacao, aberto a partir da tela de Resultados. */
    public function resultadoAvaliacao(
        Avaliacao $avaliacao,
        Request $request,
        ResultadoConsultaService $consultaService,
        RelatorioAlunoService $relatorioService,
        AnaliseConsolidadaService $analiseService,
        VisualizacaoConfigService $visualizacaoConfig,
    ): View|RedirectResponse {
        $aluno = $this->alunoAutenticado();

        if ($aluno === null) {
            return redirect()->route('portal.consulta');
        }

        $periodo = (string) $request->query('periodo', '');
        $resultado = $consultaService->buscarUmaAvaliacao($aluno, $avaliacao->codigo, $periodo);

        if ($resultado === null) {
            abort(404);
        }

        $estado = $visualizacaoConfig->estadoCompleto($avaliacao);
        $visivel = fn (string $chave) => $estado[$chave]['visivelAluno'];

        $respostas = $resultado['respostas'];
        $gabaritos = $resultado['gabaritos'];

        // Período do aluno NESTA prova (o da planilha de resultados): decide quais questões ele precisava acertar.
        $periodoAluno = PeriodoCurso::ordinal($resultado['periodo']);
        $questoesAFrente = $periodoAluno === null ? 0 : Anulacao::excluirDistribuidas(
            Questao::where('avaliacao_codigo', $avaliacao->codigo)
                ->whereNotNull('gabarito')->where('gabarito', '!=', '')
                ->where('periodo_minimo', '>', $periodoAluno)
        )->count();

        $desempenhoAreaMeta = $visivel('desempenho_area') ? $relatorioService->desempenhoPorAreaComMeta($respostas, $gabaritos, $avaliacao, $periodoAluno) : null;
        $trilhaEstudo = $visivel('trilha_estudo') ? $relatorioService->trilhaDeEstudo($respostas, $gabaritos, $avaliacao, 6, $periodoAluno) : null;

        // Resultado geral x esperado para o período do aluno nesta prova (60% quando a prova não traz a meta).
        $meta = $analiseService->metaPorPeriodo($aluno, [$avaliacao->codigo]);
        $daProva = $meta['avaliacoes'][0] ?? null;

        // Cada trecho da leitura só usa o que o aluno PODE ver nesta avaliação (a configuração de visuais vale também aqui).
        $leituraDaProva = LeituraDaProva::gerar([
            'percentual' => $estado['nota_geral']['visivelAluno'] ? ($resultado['percentual'] ?? $daProva['percentual'] ?? null) : null,
            'minimo' => $daProva['minimo'] ?? AnaliseConsolidadaService::MINIMO_PADRAO,
            'comMeta' => $daProva['comMeta'] ?? false,
            'periodoAluno' => $periodoAluno,
            'areas' => $desempenhoAreaMeta ?? [],
            'bloom' => $visivel('desempenho_bloom') ? $relatorioService->desempenhoPorBloomComContagem($respostas, $gabaritos, $avaliacao) : [],
            'adiante' => $meta['adiante'] ?? ['total' => 0, 'acertos' => 0],
            'temTrilha' => ! empty($trilhaEstudo),
        ]);

        return view('portal.resultado-avaliacao', [
            'aluno' => $aluno,
            'r' => $resultado,
            'estado' => $estado,
            'periodoAluno' => $periodoAluno,
            'questoesAFrente' => $questoesAFrente,
            'radarDisciplina' => $visivel('radar_disciplina') ? $relatorioService->radarDisciplina($respostas, $gabaritos, $avaliacao) : null,
            'desempenhoAreaMeta' => $desempenhoAreaMeta,
            'leituraDaProva' => $leituraDaProva,
            'desempenhoAreaContagem' => $visivel('desempenho_area') ? $relatorioService->desempenhoPorAreaComContagem($respostas, $gabaritos, $avaliacao) : null,
            'lacunasConsolidados' => $visivel('lacunas_conhecimentos') ? $relatorioService->lacunasEConsolidados($respostas, $gabaritos, $avaliacao, $periodoAluno) : null,
            'trilhaEstudo' => $trilhaEstudo,
            'desempenhoMiller' => $visivel('desempenho_miller') ? $relatorioService->desempenhoPorMiller($respostas, $gabaritos, $avaliacao) : null,
        ]);
    }

    /**
     * Encerra a sessão do boletim — útil em computador compartilhado (labs,
     * secretaria). invalidate() (não só forget()) + regenerateToken(), igual
     * a Auth\LoginController::destroy(): descarta o ID de sessão inteiro,
     * não só o vínculo com o aluno, senão quem soubesse esse ID de sessão
     * continuaria com acesso mesmo depois do "sair".
     */
    public function sair(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.consulta');
    }

    /**
     * regenerate() no momento da autenticação — mesmo padrão de
     * Auth\LoginController::store() — pra um ID de sessão fixado antes do
     * login (ex.: por quem usou o computador antes, num lab/secretaria) não
     * continuar válido depois que o aluno se autentica.
     */
    private function autenticarEIrParaResultados(Request $request, Aluno $aluno): RedirectResponse
    {
        $request->session()->regenerate();
        session(['portal_aluno_id' => $aluno->id]);

        return redirect()->route('portal.resultados');
    }

    private function alunoAutenticado(): ?Aluno
    {
        $id = session('portal_aluno_id');

        return $id ? Aluno::find($id) : null;
    }

    /**
     * "Período letivo" (2026/1, 2026/2...) é o semestre, derivado da data da
     * avaliação por ResultadoConsultaService::periodoLetivo() — não confundir
     * com `periodo` (`$r['periodo']`), que é o período do CURSO do aluno
     * (ex.: "5º"), vindo da planilha de resultados. O filtro por padrão
     * mostra só o período letivo mais recente (`''` na query string =
     * "Todos", igual ao filtro equivalente no admin).
     */
    private function renderizarResultados(Aluno $aluno, ResultadoConsultaService $consultaService, RelatorioAlunoService $relatorioService, AnaliseConsolidadaService $analiseService, InsightService $insightService, ExplicacaoVisualService $explicacaoService, Request $request): View
    {
        $todos = $consultaService->buscarPorAluno($aluno);

        $periodosDisponiveis = collect($todos)
            ->pluck('periodo_letivo')
            ->filter(fn ($p) => $p !== null && $p !== '')
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        $periodoSelecionado = $request->has('periodo_letivo')
            ? (string) $request->query('periodo_letivo', '')
            : (string) ($periodosDisponiveis[0] ?? '');

        $resultados = $periodoSelecionado === ''
            ? $todos
            : collect($todos)->filter(fn ($r) => $r['periodo_letivo'] === $periodoSelecionado)->values()->all();

        $arvore = $consultaService->montarArvore($resultados);
        $avaliacaoCodigos = collect($resultados)->pluck('avaliacao.codigo')->unique()->values()->all();

        // Estas continuam calculadas pro PERÍODO INTEIRO só porque
        // InsightService::gerar() usa (habilidade mais fraca/forte, nível de
        // Bloom mais difícil) — não são renderizadas como seção própria: cada
        // categoria tem a sua, escopada só às avaliações dela (ver
        // anexarAnaliseNaArvore()), pra não misturar categorias diferentes
        // numa tela só nem virar uma tela só de gráficos quando o aluno tem
        // muito resultado. O boletim não compara o aluno com a turma nos
        // cards de resumo.
        $evolucaoPorCategoria = $relatorioService->evolucaoPorCategoria($resultados);
        $coberturaHabilidade = $analiseService->coberturaHabilidade($aluno, $avaliacaoCodigos);
        $bloom = $analiseService->bloomComContagem($aluno, $avaliacaoCodigos);

        $evolucaoPorCategoriaPorId = [];
        foreach ($evolucaoPorCategoria as $cat) {
            $evolucaoPorCategoriaPorId[$cat['categoria_id']] = $cat['pontos'];
        }

        $temAnaliseNaArvore = false;
        $arvore['arvore'] = $this->anexarAnaliseNaArvore(
            $aluno,
            $arvore['arvore'],
            $evolucaoPorCategoriaPorId,
            $analiseService,
            $explicacaoService,
            $temAnaliseNaArvore,
        );

        return view('portal.resultados', [
            'aluno' => $aluno,
            'totalAvaliacoes' => count($resultados),
            'periodosDisponiveis' => $periodosDisponiveis,
            'periodoSelecionado' => $periodoSelecionado,
            'insights' => $insightService->gerar($aluno, $resultados, $evolucaoPorCategoria, $coberturaHabilidade, $bloom),
            'temAnaliseNaArvore' => $temAnaliseNaArvore,
            ...$arvore,
        ]);
    }

    /**
     * Anexa, em CADA nó da árvore de categorias (montarArvore()), a evolução
     * histórica, o acerto x esperado para o período do aluno e a análise consolidada (TRI, habilidade, Miller)
     * escopadas só às
     * avaliações DAQUELE nó — mesmos serviços que antes calculavam isso pro
     * período inteiro (misturando categorias diferentes na mesma seção da
     * tela). Um nó "pasta" (só com subcategorias, sem avaliação própria) não
     * tem nada pra mostrar aqui — os serviços já voltam vazio/null pra uma
     * lista de avaliações vazia — mas a recursão ainda desce pras filhas.
     *
     * @param  array<int, array<string, mixed>>  $nos  nível da árvore (raízes ou subcategorias) de montarArvore()
     * @param  array<int, array<int, array{codigo:int,nome:string,data:string,percentual:float}>>  $evolucaoPorCategoriaPorId  categoria_id => pontos, já filtrado (≥2 avaliações) por RelatorioAlunoService::evolucaoPorCategoria()
     * @return array<int, array<string, mixed>>
     */
    private function anexarAnaliseNaArvore(
        Aluno $aluno,
        array $nos,
        array $evolucaoPorCategoriaPorId,
        AnaliseConsolidadaService $analiseService,
        ExplicacaoVisualService $explicacaoService,
        bool &$temAlgumaAnalise,
    ): array {
        foreach ($nos as &$no) {
            $avaliacaoCodigos = collect($no['resultados'])->pluck('avaliacao.codigo')->unique()->values()->all();

            // null (e não a estrutura vazia) quando não há área nenhuma: o
            // ['avaliacoes' => [], 'areas' => []] passaria pelo ! empty()
            // logo abaixo e ligaria a seção de análise sem nada dentro.
            $mapaDominio = $analiseService->mapaDominio($aluno, $avaliacaoCodigos);

            $metaPeriodo = $analiseService->metaPorPeriodo($aluno, $avaliacaoCodigos);

            $no['analise'] = [
                'evolucaoHistorica' => $evolucaoPorCategoriaPorId[$no['categoria']->id] ?? [],
                'metaPeriodo' => $metaPeriodo,
                'leituraRapida' => LeituraMetaPeriodo::gerar($metaPeriodo),
                'dispersaoTri' => $analiseService->dispersaoTri($aluno, $avaliacaoCodigos),
                'coberturaHabilidade' => $analiseService->coberturaHabilidade($aluno, $avaliacaoCodigos),
                'miller' => $analiseService->desempenhoMillerConsolidado($aluno, $avaliacaoCodigos),
                'mapaDominio' => $mapaDominio['areas'] === [] ? null : $mapaDominio,
            ];

            $no['explicacoes'] = $explicacaoService->gerar($no['analise']);

            if (! $temAlgumaAnalise && collect($no['analise'])->contains(fn ($v) => ! empty($v))) {
                $temAlgumaAnalise = true;
            }

            if (! empty($no['subcategorias'])) {
                $no['subcategorias'] = $this->anexarAnaliseNaArvore(
                    $aluno,
                    $no['subcategorias'],
                    $evolucaoPorCategoriaPorId,
                    $analiseService,
                    $explicacaoService,
                    $temAlgumaAnalise,
                );
            }
        }

        return $nos;
    }

    /** @return array{recaptchaAtivo: bool, recaptchaSiteKey: string, hcaptchaAtivo: bool, hcaptchaSiteKey: string} */
    private function configuracaoCaptcha(): array
    {
        return [
            'recaptchaAtivo' => Configuracao::valor('recaptcha_ativo', '0') === '1',
            'recaptchaSiteKey' => Configuracao::valor('recaptcha_site_key', ''),
            'hcaptchaAtivo' => Configuracao::valor('hcaptcha_ativo', '0') === '1',
            'hcaptchaSiteKey' => Configuracao::valor('hcaptcha_site_key', ''),
        ];
    }

    private function validarCaptcha(Request $request, CaptchaVerifier $captcha): ?string
    {
        if (Configuracao::valor('recaptcha_ativo', '0') === '1') {
            $token = (string) $request->input('g-recaptcha-response', '');
            $secret = Configuracao::valor('recaptcha_secret_key', '');

            if ($token === '') {
                return 'Por favor, confirme que você não é um robô.';
            }
            if ($secret === '') {
                return $this->captchaSemSegredo('reCAPTCHA');
            }
            if (! $captcha->verificarRecaptcha($secret, $token)) {
                return 'Falha na validação do reCAPTCHA. Tente novamente.';
            }
        } elseif (Configuracao::valor('hcaptcha_ativo', '0') === '1') {
            $token = (string) $request->input('h-captcha-response', '');
            $secret = Configuracao::valor('hcaptcha_secret_key', '');

            if ($token === '') {
                return 'Por favor, confirme que você não é um robô.';
            }
            if ($secret === '') {
                return $this->captchaSemSegredo('hCaptcha');
            }
            if (! $captcha->verificarHcaptcha($secret, $token)) {
                return 'Falha na validação do hCaptcha. Tente novamente.';
            }
        }

        return null;
    }

    /**
     * CAPTCHA ativo mas sem a secret key: falha FECHADA. Antes, a verificação era simplesmente pulada
     * (bastava qualquer texto no campo do token) — um CAPTCHA que não protege, mas parece que protege.
     */
    private function captchaSemSegredo(string $nome): string
    {
        Log::warning("{$nome} está ativo mas sem secret key configurada: consulta recusada.");

        return 'A verificação anti-robô está indisponível no momento. Tente novamente mais tarde ou procure a coordenação.';
    }

    private function emitirCodigo(string $cpf, Aluno $aluno, SmtpEmailSender $mailer): void
    {
        $codigo = sprintf('%06d', random_int(0, 999999));

        VerificacaoEmail::where('cpf', $cpf)->delete();
        VerificacaoEmail::create([
            'cpf' => $cpf,
            'codigo' => VerificacaoEmail::hashDoCodigo($cpf, $codigo),
            'expira_em' => Carbon::now()->addMinutes(10),
            'vezes_reenviado' => 0,
        ]);

        $mailer->enviar($aluno->emailParaCodigo(), $this->montarTexto('subject', $aluno, $codigo), $this->montarTexto('body', $aluno, $codigo));
    }

    private function montarTexto(string $parte, Aluno $aluno, string $codigo): string
    {
        $template = $parte === 'subject'
            ? Configuracao::valor('email_template_subject', self::ASSUNTO_PADRAO)
            : Configuracao::valor('email_template_body', self::CORPO_PADRAO);

        return str_replace(['[NOME_DO_ALUNO]', '[CODIGO]'], [$aluno->nome ?: 'Aluno', $codigo], $template);
    }

    private function ocultarEmail(string $email): string
    {
        [$usuario, $dominio] = explode('@', $email, 2) + ['', ''];

        return substr($usuario, 0, 3).'***@'.$dominio;
    }
}
