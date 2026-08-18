<?php

namespace App\Services\Portal;

use Illuminate\Support\Facades\Http;

/**
 * Validação server-side de reCAPTCHA v2 / hCaptcha — equivalente às
 * chamadas a siteverify em api/consulta.php.
 */
class CaptchaVerifier
{
    public function verificarRecaptcha(string $secretKey, string $resposta): bool
    {
        return $this->verificar('https://www.google.com/recaptcha/api/siteverify', $secretKey, $resposta);
    }

    public function verificarHcaptcha(string $secretKey, string $resposta): bool
    {
        return $this->verificar('https://hcaptcha.com/siteverify', $secretKey, $resposta);
    }

    private function verificar(string $url, string $secretKey, string $resposta): bool
    {
        try {
            $response = Http::asForm()->post($url, ['secret' => $secretKey, 'response' => $resposta]);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
