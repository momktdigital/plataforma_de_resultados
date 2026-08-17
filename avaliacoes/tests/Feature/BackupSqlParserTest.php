<?php

namespace Tests\Feature;

use App\Services\Legado\BackupSqlParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupSqlParserTest extends TestCase
{
    private function arquivoComConteudo(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'backup_teste_').'.sql';
        File::put($caminho, $conteudo);

        return $caminho;
    }

    public function test_extrai_linha_de_gabaritos_no_formato_do_backup_legado(): void
    {
        $sql = "-- Backup do Banco de Dados: Resultados DI\n"
            ."SET FOREIGN_KEY_CHECKS=0;\n\n"
            ."DROP TABLE IF EXISTS `gabaritos`;\n"
            ."CREATE TABLE `gabaritos` (`id` int) ENGINE=InnoDB;\n\n"
            .'INSERT INTO `gabaritos` (`id`, `nome_avaliacao`, `respostas`, `link_comentado`, `created_at`, `updated_at`, `deleted_at`) '
            ."VALUES ('1', 'ENADE 2026', '{\"Q1\":\"B\",\"Q2\":\"C\"}', NULL, '2026-01-01 10:00:00', '2026-01-01 10:00:00', NULL);\n";

        $linhas = iterator_to_array((new BackupSqlParser)->linhas($this->arquivoComConteudo($sql)));

        $this->assertCount(1, $linhas);
        $this->assertSame('gabaritos', $linhas[0]['tabela']);
        $this->assertSame('ENADE 2026', $linhas[0]['linha']->nome_avaliacao);
        $this->assertSame('{"Q1":"B","Q2":"C"}', $linhas[0]['linha']->respostas);
        $this->assertNull($linhas[0]['linha']->link_comentado);
        $this->assertNull($linhas[0]['linha']->deleted_at);
    }

    public function test_extrai_linha_de_resultados_com_aspas_e_barra_invertida_escapadas(): void
    {
        $valorComEscape = addslashes('Texto com \'aspas\' e barra \\ invertida');
        $sql = 'INSERT INTO `resultados` (`id`, `ra`, `periodo`, `nome_avaliacao`, `respostas`, `link_comentado`, `notas_finais`, `created_at`, `updated_at`, `deleted_at`) '
            ."VALUES ('9', '12345', '2026/1', 'Simulado', '{\"Q1\":\"B\"}', '{$valorComEscape}', NULL, '2026-01-01 10:00:00', '2026-01-01 10:00:00', '2026-02-01 00:00:00');\n";

        $linhas = iterator_to_array((new BackupSqlParser)->linhas($this->arquivoComConteudo($sql)));

        $this->assertCount(1, $linhas);
        $this->assertSame('resultados', $linhas[0]['tabela']);
        $this->assertSame('12345', $linhas[0]['linha']->ra);
        $this->assertSame('2026/1', $linhas[0]['linha']->periodo);
        $this->assertSame("Texto com 'aspas' e barra \\ invertida", $linhas[0]['linha']->link_comentado);
        $this->assertSame('2026-02-01 00:00:00', $linhas[0]['linha']->deleted_at);
    }

    public function test_ignora_linhas_de_outras_tabelas(): void
    {
        $sql = "INSERT INTO `admins` (`id`, `username`) VALUES ('1', 'admin');\n"
            ."INSERT INTO `configuracoes` (`id`, `chave`) VALUES ('1', 'x');\n";

        $linhas = iterator_to_array((new BackupSqlParser)->linhas($this->arquivoComConteudo($sql)));

        $this->assertCount(0, $linhas);
    }

    public function test_processa_arquivo_com_muitas_linhas_misturadas(): void
    {
        $sql = "INSERT INTO `admins` (`id`) VALUES ('1');\n"
            ."INSERT INTO `gabaritos` (`id`, `nome_avaliacao`, `respostas`, `link_comentado`, `created_at`, `updated_at`, `deleted_at`) VALUES ('1', 'A', '{}', NULL, NULL, NULL, NULL);\n"
            ."INSERT INTO `resultados` (`id`, `ra`, `periodo`, `nome_avaliacao`, `respostas`, `link_comentado`, `notas_finais`, `created_at`, `updated_at`, `deleted_at`) VALUES ('1', '1', 'p', 'A', '{}', NULL, NULL, NULL, NULL, NULL);\n"
            ."INSERT INTO `alunos` (`id`) VALUES ('1');\n";

        $linhas = iterator_to_array((new BackupSqlParser)->linhas($this->arquivoComConteudo($sql)));

        $this->assertCount(2, $linhas);
        $this->assertSame(['gabaritos', 'resultados'], array_column($linhas, 'tabela'));
    }
}
