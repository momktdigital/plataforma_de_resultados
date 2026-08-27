<?php

namespace Tests\Unit;

use App\Support\Anulacao;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Anulacao::acertou() (PHP) e Anulacao::condicaoAcertoSql() (fragmento SQL
 * cru) são duas implementações independentes da MESMA regra, usadas em
 * paralelo por cinco serviços diferentes (ver o comentário da classe) — só
 * eram testadas indiretamente, via Feature tests que tocam banco e nunca
 * comparam as duas formas lado a lado. Este teste roda os dois lados pra
 * cada combinação relevante de resposta/gabarito/anulada_modo e falha se
 * algum dia divergirem.
 *
 * Usa um PDO SQLite em memória só como motor de avaliação da string SQL
 * gerada (não é o banco da aplicação, não passa pelo container do Laravel,
 * não roda migration nenhuma) — por isso continua um teste rápido e isolado,
 * não um teste de integração.
 */
class AnulacaoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
    }

    /**
     * gabarito nunca é null/vazio aqui de propósito: todo chamador real de
     * condicaoAcertoSql() já filtra isso fora antes (`gabarito IS NOT NULL
     * AND gabarito != ''` — ver ResumoResultadoService/RelatorioAdminService),
     * então comparar contra gabarito ausente não é um cenário que acontece
     * em produção.
     *
     * @return array<string, array{0: ?string, 1: string, 2: ?string}>
     */
    public static function combinacoes(): array
    {
        $respostas = [null, '', 'A', 'B', 'BLANK', '-'];
        $gabaritos = ['A', 'B'];
        $modos = [null, Anulacao::MODO_DAR_PONTO, Anulacao::MODO_DISTRIBUIR_PONTUACAO];

        $casos = [];
        foreach ($respostas as $resposta) {
            foreach ($gabaritos as $gabarito) {
                foreach ($modos as $modo) {
                    $chave = 'resposta='.($resposta ?? 'NULL').',gabarito='.$gabarito.',modo='.($modo ?? 'NULL');
                    $casos[$chave] = [$resposta, $gabarito, $modo];
                }
            }
        }

        return $casos;
    }

    #[DataProvider('combinacoes')]
    public function test_acertou_e_condicao_sql_concordam(?string $resposta, string $gabarito, ?string $anuladaModo): void
    {
        $esperado = Anulacao::acertou($resposta, $gabarito, $anuladaModo);

        $sql = 'SELECT '.Anulacao::condicaoAcertoSql('resposta', 'gabarito', 'anulada_modo').' AS acertou '
            .'FROM (SELECT :resposta AS resposta, :gabarito AS gabarito, :modo AS anulada_modo)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['resposta' => $resposta, 'gabarito' => $gabarito, 'modo' => $anuladaModo]);
        $obtidoSql = (bool) $stmt->fetchColumn();

        $descricao = 'resposta='.var_export($resposta, true)
            .', gabarito='.var_export($gabarito, true)
            .', anulada_modo='.var_export($anuladaModo, true);

        $this->assertSame($esperado, $obtidoSql, "PHP e SQL divergem para {$descricao}");
    }

    public function test_distribuida_identifica_apenas_o_modo_distribuir_pontuacao(): void
    {
        $this->assertFalse(Anulacao::distribuida(null));
        $this->assertFalse(Anulacao::distribuida(Anulacao::MODO_DAR_PONTO));
        $this->assertTrue(Anulacao::distribuida(Anulacao::MODO_DISTRIBUIR_PONTUACAO));
    }

    public function test_modos_lista_as_duas_modalidades(): void
    {
        $this->assertSame([Anulacao::MODO_DAR_PONTO, Anulacao::MODO_DISTRIBUIR_PONTUACAO], Anulacao::modos());
    }
}
