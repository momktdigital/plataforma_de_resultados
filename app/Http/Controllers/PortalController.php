<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultaResultadoRequest;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Configuracao;
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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if ($erro = $this->validarCaptcha($request, $captcha)) {
            return back()->withErrors(['captcha' => $erro])->withInput();
        }

        $dataNascimento = Carbon::createFromFormat('d/m/Y', $dados['data_nascimento'])->format('Y-m-d');

        $aluno = Aluno::where('cpf', $cpf)->whereDate('data_nascimento', $dataNascimento)->first();

        if ($aluno === null) {
            return back()
                ->withErrors(['cpf' => 'Nenhum aluno encontrado com este CPF e Data de Nascimento.'])
                ->withInput();
        }

        if (Configuracao::valor('smtp_ativo', '0') === '1') {
            if (empty($aluno->email)) {
                return back()
                    ->withErrors(['cpf' => 'O 2FA está ativo, mas você não tem e-mail cadastrado. Contate a secretaria.'])
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

            return view('portal.verificar', ['cpf' => $cpf, 'emailOculto' => $this->ocultarEmail($aluno->email)]);
        }

        return $this->autenticarEIrParaResultados($request, $aluno);
    }

    public function verificar(Request $request, RateLimit2faService $rateLimiter): View|RedirectResponse
    {
        $dados = $request->validate([
            'cpf' => ['required', 'string'],
            'codigo' => ['required', 'string'],
        ]);
        $cpf = preg_replace('/\D/', '', $dados['cpf']);
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
        if (! hash_equals($verificacao->codigo, trim($dados['codigo']))) {
            $verificacao->increment('tentativas_falhas');
            $rateLimiter->registrarFalha($ip);

            $restantes = 3 - $verificacao->tentativas_falhas;

            if ($restantes <= 0) {
                return redirect()->route('portal.consulta')
                    ->withErrors(['cpf' => 'Código incorreto 3 vezes. Dispositivo bloqueado por 1h.']);
            }

            return view('portal.verificar', [
                'cpf' => $cpf,
                'emailOculto' => null,
                'erro' => "Código incorreto. Você tem mais {$restantes} tentativa(s).",
            ]);
        }

        $verificacao->delete();
        $rateLimiter->resetar($ip);

        $aluno = Aluno::where('cpf', $cpf)->first();

        if ($aluno === null) {
            return redirect()->route('portal.consulta')->withErrors(['cpf' => 'Aluno não encontrado.']);
        }

        return $this->autenticarEIrParaResultados($request, $aluno);
    }

    public function reenviar(Request $request, SmtpEmailSender $mailer): View|RedirectResponse
    {
        $dados = $request->validate(['cpf' => ['required', 'string']]);
        $cpf = preg_replace('/\D/', '', $dados['cpf']);

        $verificacao = VerificacaoEmail::where('cpf', $cpf)->latest('id')->first();

        if ($verificacao === null) {
            return redirect()->route('portal.consulta')
                ->withErrors(['cpf' => 'Nenhuma verificação pendente para este CPF. Tente fazer a consulta novamente.']);
        }

        $aluno = Aluno::where('cpf', $cpf)->first();

        if ($aluno === null || empty($aluno->email)) {
            return redirect()->route('portal.consulta')->withErrors(['cpf' => 'Aluno ou e-mail não encontrado.']);
        }

        $indice = min($verificacao->vezes_reenviado, 3);
        $minutosEspera = $verificacao->ultimo_reenvio === null ? 1 : self::ESPERAS_REENVIO[$indice];
        $referencia = $verificacao->ultimo_reenvio ?? $verificacao->criado_em;
        $fimEspera = $referencia->copy()->addMinutes($minutosEspera);

        if (Carbon::now()->lt($fimEspera)) {
            $minutosRestantes = (int) ceil(Carbon::now()->diffInSeconds($fimEspera) / 60);

            return view('portal.verificar', [
                'cpf' => $cpf,
                'emailOculto' => null,
                'erro' => "Aguarde {$minutosRestantes} minuto(s) para solicitar um novo código.",
            ]);
        }

        $verificacao->vezes_reenviado++;
        $verificacao->ultimo_reenvio = Carbon::now();
        $verificacao->expira_em = Carbon::now()->addMinutes(10);
        $verificacao->save();

        try {
            $mailer->enviar($aluno->email, '[Reenvio] '.$this->montarTexto('subject', $aluno), $this->montarTexto('body', $aluno));
        } catch (TransportExceptionInterface) {
            return view('portal.verificar', [
                'cpf' => $cpf,
                'emailOculto' => null,
                'erro' => 'Erro ao enviar o e-mail. Tente novamente.',
            ]);
        }

        return view('portal.verificar', [
            'cpf' => $cpf,
            'emailOculto' => $this->ocultarEmail($aluno->email),
            'status' => 'Código reenviado com sucesso.',
        ]);
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

        return view('portal.resultado-avaliacao', [
            'aluno' => $aluno,
            'r' => $resultado,
            'estado' => $estado,
            'comparativoTurma' => $visivel('comparativo_turma') ? $relatorioService->comparativoTurma($aluno, $avaliacao, $periodo) : null,
            'rankingPercentil' => $visivel('ranking_percentil') ? $relatorioService->rankingPercentil($aluno, $avaliacao, $periodo) : null,
            'radarDisciplina' => $visivel('radar_disciplina') ? $relatorioService->radarDisciplina($respostas, $gabaritos, $avaliacao) : null,
            'desempenhoArea' => $visivel('desempenho_area') ? $relatorioService->desempenhoPorArea($respostas, $gabaritos, $avaliacao) : null,
            'desempenhoAreaContagem' => $visivel('desempenho_area') ? $relatorioService->desempenhoPorAreaComContagem($respostas, $gabaritos, $avaliacao) : null,
            'lacunasConsolidados' => $visivel('lacunas_conhecimentos') ? $relatorioService->lacunasEConsolidados($respostas, $gabaritos, $avaliacao) : null,
            'desempenhoBloom' => $visivel('desempenho_bloom') ? $relatorioService->desempenhoPorBloom($respostas, $gabaritos, $avaliacao) : null,
            'desempenhoMiller' => $visivel('desempenho_miller') ? $relatorioService->desempenhoPorMiller($respostas, $gabaritos, $avaliacao) : null,
            'comparativoQuestao' => $visivel('comparativo_questao') ? $relatorioService->comparativoQuestao($avaliacao, $periodo, $respostas, $gabaritos) : null,
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

        $comPercentual = collect($resultados)->pluck('percentual')->filter(fn ($p) => $p !== null);
        $arvore = $consultaService->montarArvore($resultados);
        $avaliacaoCodigos = collect($resultados)->pluck('avaliacao.codigo')->unique()->values()->all();

        // Estas duas continuam calculadas pro PERÍODO INTEIRO só porque
        // InsightService::gerar() usa (comparativo geral com a turma,
        // habilidade mais fraca/forte do período) — não são mais renderizadas
        // como seção própria: cada categoria agora tem a sua, escopada só às
        // avaliações dela (ver anexarAnaliseNaArvore()), pra não misturar
        // categorias diferentes numa tela só nem virar uma tela só de gráficos
        // quando o aluno tem muito resultado.
        $evolucaoPorCategoria = $relatorioService->evolucaoPorCategoria($resultados);
        $comparativoTurmaConsolidado = $relatorioService->comparativoTurmaConsolidado($aluno, $resultados);
        $coberturaHabilidade = $analiseService->coberturaHabilidade($aluno, $avaliacaoCodigos);

        $evolucaoPorCategoriaPorId = [];
        foreach ($evolucaoPorCategoria as $cat) {
            $evolucaoPorCategoriaPorId[$cat['categoria_id']] = $cat['pontos'];
        }

        $temAnaliseNaArvore = false;
        $arvore['arvore'] = $this->anexarAnaliseNaArvore(
            $aluno,
            $arvore['arvore'],
            $evolucaoPorCategoriaPorId,
            $relatorioService,
            $analiseService,
            $explicacaoService,
            $temAnaliseNaArvore,
        );

        return view('portal.resultados', [
            'aluno' => $aluno,
            'totalAvaliacoes' => count($resultados),
            'mediaGeral' => $comPercentual->isNotEmpty() ? round($comPercentual->avg(), 1) : null,
            'periodosDisponiveis' => $periodosDisponiveis,
            'periodoSelecionado' => $periodoSelecionado,
            'insights' => $insightService->gerar($aluno, $resultados, $evolucaoPorCategoria, $comparativoTurmaConsolidado, $coberturaHabilidade),
            'resumoPorCategoria' => $consultaService->resumoPorCategoria($arvore['arvore']),
            'temAnaliseNaArvore' => $temAnaliseNaArvore,
            ...$arvore,
        ]);
    }

    /**
     * Anexa, em CADA nó da árvore de categorias (montarArvore()), a evolução
     * histórica e a análise consolidada (dificuldade, TRI, habilidade, Bloom/
     * Miller, comparativo com turma, questões divergentes) escopadas só às
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
        RelatorioAlunoService $relatorioService,
        AnaliseConsolidadaService $analiseService,
        ExplicacaoVisualService $explicacaoService,
        bool &$temAlgumaAnalise,
    ): array {
        foreach ($nos as &$no) {
            $avaliacaoCodigos = collect($no['resultados'])->pluck('avaliacao.codigo')->unique()->values()->all();

            $no['analise'] = [
                'evolucaoHistorica' => $evolucaoPorCategoriaPorId[$no['categoria']->id] ?? [],
                'comparativoTurma' => $relatorioService->comparativoTurmaConsolidado($aluno, $no['resultados']),
                'curvaDificuldade' => $analiseService->curvaDificuldadePedagogica($aluno, $avaliacaoCodigos),
                'dispersaoTri' => $analiseService->dispersaoTri($aluno, $avaliacaoCodigos),
                'coberturaHabilidade' => $analiseService->coberturaHabilidade($aluno, $avaliacaoCodigos),
                'bloom' => $analiseService->desempenhoBloomConsolidado($aluno, $avaliacaoCodigos),
                'miller' => $analiseService->desempenhoMillerConsolidado($aluno, $avaliacaoCodigos),
                'divergentes' => $analiseService->questoesDivergentesDaTurma($aluno, $avaliacaoCodigos),
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
                    $relatorioService,
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
            if ($secret !== '' && ! $captcha->verificarRecaptcha($secret, $token)) {
                return 'Falha na validação do reCAPTCHA. Tente novamente.';
            }
        } elseif (Configuracao::valor('hcaptcha_ativo', '0') === '1') {
            $token = (string) $request->input('h-captcha-response', '');
            $secret = Configuracao::valor('hcaptcha_secret_key', '');

            if ($token === '') {
                return 'Por favor, confirme que você não é um robô.';
            }
            if ($secret !== '' && ! $captcha->verificarHcaptcha($secret, $token)) {
                return 'Falha na validação do hCaptcha. Tente novamente.';
            }
        }

        return null;
    }

    private function emitirCodigo(string $cpf, Aluno $aluno, SmtpEmailSender $mailer): void
    {
        $codigo = sprintf('%06d', random_int(0, 999999));

        VerificacaoEmail::where('cpf', $cpf)->delete();
        VerificacaoEmail::create([
            'cpf' => $cpf,
            'codigo' => $codigo,
            'expira_em' => Carbon::now()->addMinutes(10),
            'vezes_reenviado' => 0,
        ]);

        $mailer->enviar($aluno->email, $this->montarTexto('subject', $aluno, $codigo), $this->montarTexto('body', $aluno, $codigo));
    }

    private function montarTexto(string $parte, Aluno $aluno, ?string $codigoForcado = null): string
    {
        $codigo = $codigoForcado ?? VerificacaoEmail::where('cpf', $aluno->cpf)->latest('id')->value('codigo') ?? '';

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
