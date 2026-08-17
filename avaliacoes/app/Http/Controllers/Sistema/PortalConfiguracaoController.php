<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarPortalAparenciaRequest;
use App\Http\Requests\AtualizarPortalCaptchaRequest;
use App\Http\Requests\AtualizarPortalSmtpRequest;
use App\Models\Configuracao;
use App\Support\LogoUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Configurações compartilhadas com o portal público legado (título/logo do
 * site, CAPTCHA, SMTP + template do e-mail de 2FA) — tabela `configuracoes`.
 * Porta admin/configuracoes.php (+ api_test_smtp.php/api_verify_test_smtp.php)
 * para cá; salvar aqui já reflete no portal legado, que lê a mesma tabela.
 */
class PortalConfiguracaoController extends Controller
{
    private const ASSUNTO_PADRAO = 'Seu código de acesso aos resultados';

    private const CORPO_PADRAO = 'Olá [NOME_DO_ALUNO],<br><br>Seu código de verificação é: <b>[CODIGO]</b><br><br>Este código expira em 10 minutos.<br><br>Se você não solicitou este acesso, por favor ignore este e-mail.';

    public function index(): View
    {
        $config = Configuracao::todas();

        return view('admin.sistema.portal', [
            'siteTitle' => $config['site_title'] ?? 'Resultados DI',
            'siteLogo' => $config['site_logo'] ?? '',
            'siteLogoDark' => $config['site_logo_dark'] ?? '',
            'captchaType' => match (true) {
                ($config['recaptcha_ativo'] ?? '0') === '1' => 'recaptcha',
                ($config['hcaptcha_ativo'] ?? '0') === '1' => 'hcaptcha',
                default => 'none',
            },
            'recaptchaSiteKey' => $config['recaptcha_site_key'] ?? '',
            'recaptchaSecretKey' => $config['recaptcha_secret_key'] ?? '',
            'hcaptchaSiteKey' => $config['hcaptcha_site_key'] ?? '',
            'hcaptchaSecretKey' => $config['hcaptcha_secret_key'] ?? '',
            'smtpAtivo' => ($config['smtp_ativo'] ?? '0') === '1',
            'smtpHost' => $config['smtp_host'] ?? '',
            'smtpPort' => $config['smtp_port'] ?? '',
            'smtpUser' => $config['smtp_user'] ?? '',
            'smtpFromEmail' => $config['smtp_from_email'] ?? '',
            'smtpFromName' => $config['smtp_from_name'] ?? '',
            'smtpPassExists' => ! empty($config['smtp_pass']),
            'emailTemplateSubject' => $config['email_template_subject'] ?? self::ASSUNTO_PADRAO,
            'emailTemplateBody' => $config['email_template_body'] ?? self::CORPO_PADRAO,
        ]);
    }

    public function atualizarAparencia(AtualizarPortalAparenciaRequest $request): RedirectResponse
    {
        Configuracao::definir('site_title', $request->validated('site_title'));

        try {
            if ($request->hasFile('site_logo')) {
                Configuracao::definir('site_logo', LogoUploader::salvar($request->file('site_logo'), 'logo_light'));
            }
            if ($request->hasFile('site_logo_dark')) {
                Configuracao::definir('site_logo_dark', LogoUploader::salvar($request->file('site_logo_dark'), 'logo_dark'));
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['site_logo' => $e->getMessage()]);
        }

        return redirect()->route('sistema.portal.index')->with('status', 'Configurações de aparência salvas com sucesso.');
    }

    public function atualizarCaptcha(AtualizarPortalCaptchaRequest $request): RedirectResponse
    {
        $tipo = $request->validated('captcha_type');

        Configuracao::definir('recaptcha_ativo', $tipo === 'recaptcha' ? '1' : '0');
        Configuracao::definir('recaptcha_site_key', $request->validated('recaptcha_site_key'));
        Configuracao::definir('recaptcha_secret_key', $request->validated('recaptcha_secret_key'));
        Configuracao::definir('hcaptcha_ativo', $tipo === 'hcaptcha' ? '1' : '0');
        Configuracao::definir('hcaptcha_site_key', $request->validated('hcaptcha_site_key'));
        Configuracao::definir('hcaptcha_secret_key', $request->validated('hcaptcha_secret_key'));

        return redirect()->route('sistema.portal.index')->with('status', 'Configurações de CAPTCHA salvas com sucesso.');
    }

    public function atualizarSmtp(AtualizarPortalSmtpRequest $request): RedirectResponse
    {
        Configuracao::definir('smtp_ativo', $request->boolean('smtp_ativo') ? '1' : '0');
        Configuracao::definir('smtp_from_name', $request->validated('smtp_from_name'));
        Configuracao::definir('smtp_from_email', $request->validated('smtp_from_email'));
        Configuracao::definir('smtp_user', $request->validated('smtp_user'));
        Configuracao::definir('smtp_host', $request->validated('smtp_host'));
        Configuracao::definir('smtp_port', $request->validated('smtp_port'));

        if ($request->filled('smtp_pass')) {
            Configuracao::definir('smtp_pass', $request->validated('smtp_pass'));
        }

        Configuracao::definir('email_template_subject', $request->validated('email_template_subject') ?: self::ASSUNTO_PADRAO);
        Configuracao::definir('email_template_body', $request->validated('email_template_body') ?: self::CORPO_PADRAO);

        return redirect()->route('sistema.portal.index')->with('status', 'Configurações de e-mail (SMTP) salvas com sucesso.');
    }

    public function testarSmtp(Request $request): JsonResponse
    {
        $dados = $request->validate(['email' => ['required', 'email']]);

        $codigo = sprintf('%06d', random_int(0, 999999));
        $request->session()->put('smtp_test_code', $codigo);

        try {
            $transport = new EsmtpTransport(
                Configuracao::valor('smtp_host', ''),
                (int) Configuracao::valor('smtp_port', '587'),
            );
            $transport->setUsername(Configuracao::valor('smtp_user', ''));
            $transport->setPassword(Configuracao::valor('smtp_pass', ''));

            $email = (new Email)
                ->from(Configuracao::valor('smtp_from_email', ''))
                ->to($dados['email'])
                ->subject('Teste de Configuração SMTP')
                ->html("Olá,<br><br>Seu código de teste é: <b>{$codigo}</b><br><br>Insira este código na tela de configuração para validar.");

            (new Mailer($transport))->send($email);

            return response()->json(['status' => 'success']);
        } catch (TransportExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => 'Falha no envio: '.$e->getMessage()], 500);
        }
    }

    public function verificarTesteSmtp(Request $request): JsonResponse
    {
        $dados = $request->validate(['codigo' => ['required', 'string']]);

        $esperado = $request->session()->get('smtp_test_code');

        if ($esperado === null) {
            return response()->json(['status' => 'error', 'message' => 'Nenhum teste pendente. Feche e tente novamente.'], 400);
        }

        if ($esperado !== mb_strtoupper(trim($dados['codigo']))) {
            return response()->json(['status' => 'error', 'message' => 'Código incorreto.'], 400);
        }

        $request->session()->forget('smtp_test_code');

        return response()->json(['status' => 'success']);
    }
}
