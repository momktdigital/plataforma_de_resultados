<?php

namespace Tests\Feature;

use App\Support\LimitesUpload;
use Tests\TestCase;

class UploadTooLargeTest extends TestCase
{
    public function test_pagina_de_erro_413_explica_o_problema(): void
    {
        $html = view('errors.413')->render();

        $this->assertStringContainsString('post_max_size', $html);
        $this->assertStringContainsString('client_max_body_size', $html);
    }

    public function test_limites_upload_converte_notacao_do_php_ini(): void
    {
        $this->assertSame(2 * 1024 * 1024, LimitesUpload::paraBytes('2M'));
        $this->assertSame(8 * 1024 * 1024, LimitesUpload::paraBytes('8M'));
        $this->assertSame(1024, LimitesUpload::paraBytes('1K'));
        $this->assertSame(1024 * 1024 * 1024, LimitesUpload::paraBytes('1G'));
        $this->assertSame(PHP_INT_MAX, LimitesUpload::paraBytes('-1'));
        $this->assertSame(500, LimitesUpload::paraBytes('500'));
    }

    public function test_limite_efetivo_e_o_menor_entre_post_max_size_e_upload_max_filesize(): void
    {
        // Neste ambiente de teste: post_max_size=8M, upload_max_filesize=2M.
        $this->assertSame(
            min(LimitesUpload::paraBytes(ini_get('post_max_size')), LimitesUpload::paraBytes(ini_get('upload_max_filesize'))),
            LimitesUpload::limiteEfetivoEmBytes(),
        );
    }
}
