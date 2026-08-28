<?php

namespace Tests\Unit;

use App\Support\EnvFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EnvFileWriter é o passo mais destrutivo do wizard de instalação — grava
 * credenciais de banco direto no `.env` real da aplicação (InstallController
 * não permite apontar pra outro arquivo), então não dá pra exercitar via
 * Feature test sem arriscar corromper o `.env` do próprio ambiente de teste.
 * Testa a classe isolada, contra um arquivo `.env` descartável em vez do real.
 */
class EnvFileWriterTest extends TestCase
{
    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arquivo = tempnam(sys_get_temp_dir(), 'env_test_');
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    public function test_adiciona_chaves_novas_a_um_arquivo_vazio(): void
    {
        (new EnvFileWriter($this->arquivo))->atualizar([
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'avaliacoes',
        ]);

        $conteudo = file_get_contents($this->arquivo);

        $this->assertStringContainsString('DB_HOST=127.0.0.1', $conteudo);
        $this->assertStringContainsString('DB_DATABASE=avaliacoes', $conteudo);
    }

    public function test_atualiza_chave_existente_sem_duplicar_a_linha(): void
    {
        file_put_contents($this->arquivo, "APP_NAME=Antigo\nDB_HOST=old-host\nAPP_DEBUG=true\n");

        (new EnvFileWriter($this->arquivo))->atualizar(['DB_HOST' => 'novo-host']);

        $conteudo = file_get_contents($this->arquivo);

        $this->assertSame(1, substr_count($conteudo, 'DB_HOST='));
        $this->assertStringContainsString('DB_HOST=novo-host', $conteudo);
        // Linhas ao redor preservadas.
        $this->assertStringContainsString('APP_NAME=Antigo', $conteudo);
        $this->assertStringContainsString('APP_DEBUG=true', $conteudo);
    }

    public function test_valor_com_caracteres_especiais_e_colocado_entre_aspas(): void
    {
        (new EnvFileWriter($this->arquivo))->atualizar(['DB_PASSWORD' => 'senha com espaço e "aspas"']);

        $conteudo = file_get_contents($this->arquivo);

        $this->assertStringContainsString('DB_PASSWORD="senha com espaço e \\"aspas\\""', $conteudo);
    }

    public function test_valor_vazio_ou_nulo_grava_chave_sem_valor(): void
    {
        (new EnvFileWriter($this->arquivo))->atualizar(['DB_PASSWORD' => '']);

        $this->assertStringContainsString("DB_PASSWORD=\n", file_get_contents($this->arquivo)."\n");
    }

    public function test_lanca_excecao_quando_diretorio_nao_existe(): void
    {
        // is_writable() de um arquivo com permissão só-leitura não é um bom
        // sinal aqui: rodando como root (comum em contêiner de CI/deploy),
        // o SO deixa o processo escrever mesmo assim — chmod não bastaria
        // pra forçar o caminho de erro. Um diretório inexistente falha o
        // is_writable() pra qualquer usuário, root incluso.
        $this->expectException(RuntimeException::class);

        (new EnvFileWriter('/caminho/que/nao/existe/.env'))->atualizar(['X' => 'y']);
    }
}
