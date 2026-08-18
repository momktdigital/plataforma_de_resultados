<?php

namespace App\Services\Portal;

use App\Models\Configuracao;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Envia e-mail via SMTP puro (Symfony Mailer), com credenciais lidas da
 * tabela `configuracoes` (editáveis pelo admin em Configurações → Portal
 * público) — não usa o `config/mail.php` do Laravel, o mesmo motivo de
 * App\Http\Controllers\Sistema\PortalConfiguracaoController::testarSmtp.
 */
class SmtpEmailSender
{
    public function enviar(string $destinatario, string $assunto, string $corpoHtml): void
    {
        $transport = new EsmtpTransport(
            Configuracao::valor('smtp_host', ''),
            (int) Configuracao::valor('smtp_port', '587'),
        );
        $transport->setUsername(Configuracao::valor('smtp_user', ''));
        $transport->setPassword(Configuracao::valor('smtp_pass', ''));

        $email = (new Email)
            ->from(Configuracao::valor('smtp_from_email', ''))
            ->to($destinatario)
            ->subject($assunto)
            ->html($corpoHtml)
            ->text(strip_tags($corpoHtml));

        (new Mailer($transport))->send($email);
    }
}
