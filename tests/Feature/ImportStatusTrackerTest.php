<?php

namespace Tests\Feature;

use App\Models\ConfiguracaoSistema;
use App\Support\ImportResult;
use App\Support\ImportStatusTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportStatusTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_de_ignoradas_e_limitada_mas_o_total_continua_correto(): void
    {
        // Bug relatado: um import com milhares de linhas ignoradas gravava o
        // motivo de TODAS elas em configuracoes_sistema.valor — um JSON
        // grande o bastante estourava o limite da coluna no MySQL de
        // produção, e o INSERT falhava DEPOIS que os dados de verdade
        // (respostas/resultado_resumos) já tinham sido commitados, fazendo o
        // import aparecer como "falhou" mesmo tendo gravado tudo.
        $resultado = new ImportResult;
        for ($i = 1; $i <= 600; $i++) {
            $resultado->registrarLinha();
            $resultado->ignorarLinha($i, "CPF inválido: '635878160{$i}' — precisa ter 11 dígitos.");
        }

        ImportStatusTracker::iniciar('resultados', '99');
        ImportStatusTracker::concluir('resultados', '99', $resultado);

        $status = ImportStatusTracker::status('resultados', '99');

        $this->assertSame('concluido', $status['status']);
        $this->assertSame(600, $status['ignoradasTotal']);
        $this->assertCount(500, $status['ignoradas']);
        $this->assertSame(1, $status['ignoradas'][0]['linha']);
    }

    public function test_status_de_import_antigo_sem_ignoradas_total_gravado_usa_a_contagem_da_lista(): void
    {
        ImportStatusTracker::iniciar('resultados', '50');

        // Simula o formato gravado antes de existir "_ignoradas_total".
        ConfiguracaoSistema::definir('import_resultados_50_status', 'concluido');
        ConfiguracaoSistema::definir('import_resultados_50_ignoradas', json_encode([
            ['linha' => 2, 'motivo' => 'CPF inválido.'],
        ]));

        $status = ImportStatusTracker::status('resultados', '50');

        $this->assertSame(1, $status['ignoradasTotal']);
    }
}
