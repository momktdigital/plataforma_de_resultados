<?php

namespace Tests\Feature;

use App\Support\LogoUploader;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/**
 * Sem RefreshDatabase de propósito — LogoUploader não toca banco, só o
 * filesystem (via public_path(), por isso é Feature e não Unit: precisa da
 * aplicação Laravel de pé).
 */
class LogoUploaderTest extends TestCase
{
    /** @var array<int, string> caminhos absolutos gravados durante o teste, removidos no tearDown. */
    private array $arquivosGravados = [];

    protected function tearDown(): void
    {
        foreach ($this->arquivosGravados as $caminho) {
            @unlink($caminho);
        }

        parent::tearDown();
    }

    private function salvar(UploadedFile $file): string
    {
        $caminho = LogoUploader::salvar($file, 'teste');
        $this->arquivosGravados[] = public_path($caminho);

        return $caminho;
    }

    public function test_remove_tag_script_do_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><circle r="5"/></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $caminho = $this->salvar($arquivo);
        $conteudo = file_get_contents(public_path($caminho));

        $this->assertStringNotContainsString('<script', $conteudo);
        $this->assertStringNotContainsString('alert', $conteudo);
        $this->assertStringContainsString('<circle', $conteudo);
    }

    public function test_remove_atributo_de_evento_do_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect onclick="alert(2)" width="1" height="1"/></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $caminho = $this->salvar($arquivo);
        $conteudo = file_get_contents(public_path($caminho));

        $this->assertStringNotContainsString('onload', $conteudo);
        $this->assertStringNotContainsString('onclick', $conteudo);
        $this->assertStringContainsString('<rect', $conteudo);
    }

    public function test_remove_uri_javascript_de_atributo_href(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a xlink:href="javascript:alert(1)"><text>clique</text></a></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $caminho = $this->salvar($arquivo);
        $conteudo = file_get_contents(public_path($caminho));

        $this->assertStringNotContainsString('javascript:', $conteudo);
    }

    public function test_remove_foreignobject_capaz_de_embutir_html(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">'
            .'<script>alert(1)</script></body></foreignObject></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $caminho = $this->salvar($arquivo);
        $conteudo = file_get_contents(public_path($caminho));

        $this->assertStringNotContainsString('foreignObject', $conteudo);
        $this->assertStringNotContainsString('script', $conteudo);
    }

    public function test_rejeita_svg_com_doctype_customizado(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $this->expectException(RuntimeException::class);

        LogoUploader::salvar($arquivo, 'teste');
    }

    public function test_rejeita_arquivo_que_nao_e_svg_de_verdade(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', '<html><body>não é svg</body></html>');

        $this->expectException(RuntimeException::class);

        LogoUploader::salvar($arquivo, 'teste');
    }

    public function test_mantem_svg_legitimo_intacto(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="red"/></svg>';
        $arquivo = UploadedFile::fake()->createWithContent('logo.svg', $svg);

        $caminho = $this->salvar($arquivo);
        $conteudo = file_get_contents(public_path($caminho));

        $this->assertStringContainsString('<circle', $conteudo);
        $this->assertStringContainsString('fill="red"', $conteudo);
    }

    public function test_salva_raster_normalmente(): void
    {
        $arquivo = UploadedFile::fake()->image('logo.png', 20, 20);

        $caminho = $this->salvar($arquivo);

        $this->assertFileExists(public_path($caminho));
    }
}
