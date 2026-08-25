<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultaResultadoRequest;
use App\Models\Aluno;
use App\Models\Avaliacao;
use App\Models\Configuracao;
use App\Models\VerificacaoEmail;
use App\Services\Portal\CaptchaVerifier;
use App\Services\Portal\RateLimit2faService;
use App\Services\Portal\ResultadoConsultaService;
use App\Services\Portal\SmtpEmailSender;
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

        return $this->autenticarEIrParaResultados($aluno);
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

        if ($verificacao->codigo !== trim($dados['codigo'])) {
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

        return $this->autenticarEIrParaResultados($aluno);
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
    public function resultados(Request $request, ResultadoConsultaService $consultaService): View|RedirectResponse
    {
        $aluno = $this->alunoAutenticado();

        if ($aluno === null) {
            return redirect()->route('portal.consulta');
        }

        return $this->renderizarResultados($aluno, $consultaService, $request);
    }

    /** Detalhe de uma única avaliacao, aberto a partir da tela de Resultados. */
    public function resultadoAvaliacao(Avaliacao $avaliacao, Request $request, ResultadoConsultaService $consultaService): View|RedirectResponse
    {
        $aluno = $this->alunoAutenticado();

        if ($aluno === null) {
            return redirect()->route('portal.consulta');
        }

        $periodo = (string) $request->query('periodo', '');
        $resultado = $consultaService->buscarUmaAvaliacao($aluno, $avaliacao->codigo, $periodo);

        if ($resultado === null) {
            abort(404);
        }

        return view('portal.resultado-avaliacao', ['aluno' => $aluno, 'r' => $resultado]);
    }

    /** Encerra a sessão do boletim — útil em computador compartilhado (labs, secretaria). */
    public function sair(): RedirectResponse
    {
        session()->forget('portal_aluno_id');

        return redirect()->route('portal.consulta');
    }

    private function autenticarEIrParaResultados(Aluno $aluno): RedirectResponse
    {
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
    private function renderizarResultados(Aluno $aluno, ResultadoConsultaService $consultaService, Request $request): View
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

        return view('portal.resultados', [
            'aluno' => $aluno,
            'totalAvaliacoes' => count($resultados),
            'mediaGeral' => $comPercentual->isNotEmpty() ? round($comPercentual->avg(), 1) : null,
            'periodosDisponiveis' => $periodosDisponiveis,
            'periodoSelecionado' => $periodoSelecionado,
            ...$consultaService->montarArvore($resultados),
        ]);
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
