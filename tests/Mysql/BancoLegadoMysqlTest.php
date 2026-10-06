<?php

namespace Tests\Mysql;

use App\Models\Admin;
use App\Models\Aluno;
use App\Models\AlunoMatricula;
use App\Models\Avaliacao;
use App\Models\Categoria;
use App\Models\Questao;
use App\Models\Resposta;
use App\Services\ResumoResultadoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Só roda em MySQL/MariaDB (`vendor/bin/phpunit -c phpunit.mysql.xml`): reproduz o banco da aplicação LEGADA, onde as
 * tabelas `admins` e `alunos` nasceram com outro formato que o das migrations — e que o SQLite dos testes comuns não
 * enxerga. Já deixou passar dois erros que só apareceram no MySQL real:
 *
 *  - `admins.role` é um ENUM('superadmin','coordinator'): cadastrar um reitor dava "Data truncated for column 'role'";
 *  - `alunos`/`admins` têm collation `utf8mb4_general_ci` e `resultado_resumos`/`respostas`, `utf8mb4_unicode_ci`: juntar
 *    colunas de texto das duas famílias dá "Illegal mix of collations".
 *
 * ALTER TABLE confirma a transação do RefreshDatabase, então cada teste volta o esquema ao normal e limpa as tabelas
 * (em `finally`) — este banco é só de testes (tests/TestCase.php recusa qualquer outro).
 */
class BancoLegadoMysqlTest extends TestCase
{
    use RefreshDatabase;

    private int $ra = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Teste de esquema legado: só em MySQL/MariaDB (phpunit.mysql.xml).');
        }
    }

    /** O formato do banco legado: role como ENUM e collation general_ci em admins/alunos. */
    private function comoNoLegado(): void
    {
        DB::statement("ALTER TABLE `admins` MODIFY `role` ENUM('superadmin','coordinator') NOT NULL DEFAULT 'superadmin'");
        foreach (['admins', 'alunos'] as $tabela) {
            DB::statement("ALTER TABLE `{$tabela}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
    }

    /** Volta ao esquema das migrations e esvazia as tabelas (o ALTER impediu o rollback do RefreshDatabase). */
    private function restaurar(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (DB::select('SHOW TABLES') as $linha) {
            $tabela = array_values((array) $linha)[0];
            if ($tabela !== 'migrations') {
                DB::table($tabela)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();

        DB::statement("ALTER TABLE `admins` MODIFY `role` VARCHAR(20) NOT NULL DEFAULT 'superadmin'");
        foreach (['admins', 'alunos'] as $tabela) {
            DB::statement("ALTER TABLE `{$tabela}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    private function usuario(string $nome, string $role): Admin
    {
        return Admin::create(['username' => $nome, 'email' => "{$nome}@example.test", 'password_hash' => Hash::make('senha-secreta-123'), 'role' => $role]);
    }

    /** Duas avaliações da mesma categoria (Direito e Medicina), com os alunos matriculados no período — o que mais junta tabelas. */
    private function dados(): Categoria
    {
        $categoria = Categoria::create(['nome' => 'Diagnóstico Institucional']);
        foreach ([['DIREITO', [10, 7, 6, 3, null]], ['MEDICINA', [8, 5, 9, 4]]] as [$curso, $acertos]) {
            $avaliacao = Avaliacao::create(['nome' => "2026/1 - Diagnóstico {$curso}", 'data_avaliacao' => '2026-03-10', 'categoria_id' => $categoria->id]);
            foreach (range(1, 10) as $n) {
                Questao::create(['avaliacao_codigo' => $avaliacao->codigo, 'numero' => $n, 'gabarito' => 'A', 'area' => 'Clínica', 'bloom_nivel' => 'Lembrar']);
            }
            foreach ($acertos as $a) {
                $this->ra++;
                $aluno = Aluno::create(['ra' => (string) $this->ra, 'nome' => "Aluno {$this->ra}", 'curso' => $curso, 'periodo' => '3º']);
                AlunoMatricula::create(['aluno_id' => $aluno->id, 'curso' => $curso, 'periodo' => '3º', 'periodo_letivo' => '2026/1', 'status' => 'ATIVA']);
                foreach (range(1, 10) as $n) {
                    Resposta::create([
                        'avaliacao_codigo' => $avaliacao->codigo, 'aluno_id' => $aluno->id, 'ra' => $aluno->ra, 'periodo' => '3º',
                        'questao_numero' => $n, 'resposta' => $a === null ? '' : ($n <= $a ? 'A' : 'B'),
                    ]);
                }
            }
            app(ResumoResultadoService::class)->recalcular($avaliacao->codigo);
        }

        return $categoria;
    }

    public function test_a_migration_acrescenta_rector_ao_enum_do_banco_legado(): void
    {
        try {
            $this->comoNoLegado();

            // antes da migration: o MySQL recusa (era o erro 500 ao cadastrar o reitor)
            try {
                $this->usuario('reitor', Admin::ROLE_REITOR);
                $this->fail('O ENUM legado deveria recusar o perfil "rector".');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            (include database_path('migrations/2026_10_08_120000_allow_rector_role_on_admins_table.php'))->up();

            $tipo = DB::selectOne("SHOW COLUMNS FROM `admins` LIKE 'role'")->Type;
            $this->assertSame("enum('superadmin','coordinator','rector')", $tipo);
            $this->assertTrue($this->usuario('reitor', Admin::ROLE_REITOR)->fresh()->ehReitor());
            // os perfis que já existiam continuam valendo, e rodar de novo não faz nada
            $this->assertTrue($this->usuario('coord', Admin::ROLE_COORDENADOR)->fresh()->ehCoordenador());
            (include database_path('migrations/2026_10_08_120000_allow_rector_role_on_admins_table.php'))->up();
            $this->assertSame($tipo, DB::selectOne("SHOW COLUMNS FROM `admins` LIKE 'role'")->Type);
        } finally {
            $this->restaurar();
        }
    }

    public function test_painel_da_reitoria_e_visao_do_coordenador_funcionam_com_as_collations_do_legado(): void
    {
        try {
            $this->comoNoLegado();
            (include database_path('migrations/2026_10_08_120000_allow_rector_role_on_admins_table.php'))->up();

            $categoria = $this->dados();
            $reitor = $this->usuario('reitor', Admin::ROLE_REITOR);
            $recorte = ['categoria' => $categoria->id];

            // todas as telas do reitor (juntam resultado_resumos, aluno_matriculas, respostas e questoes)
            foreach (['reitor.visao', 'reitor.desempenho', 'reitor.trajetoria', 'reitor.competencias', 'reitor.evolucao', 'reitor.risco', 'reitor.itens', 'reitor.relatorio', 'reitor.cursos'] as $rota) {
                $this->actingAs($reitor, 'admin')->get(route($rota, $recorte))->assertOk();
            }
            $this->actingAs($reitor, 'admin')->get(route('reitor.xlsx', $recorte))->assertOk();

            // visão do coordenador de um curso (alunos × resumos por aluno_id/RA/CPF)
            $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']))->assertRedirect(route('coordenador.painel'));
            $aluno = Aluno::where('curso', 'DIREITO')->first();
            foreach ([route('coordenador.painel'), route('coordenador.alunos'), route('coordenador.alunos.show', $aluno), route('coordenador.desempenho'), route('coordenador.comparativo'), route('avaliacoes.index')] as $url) {
                $this->actingAs($reitor, 'admin')->get($url)->assertOk();
            }
        } finally {
            $this->restaurar();
        }
    }

    public function test_cadastro_de_usuarios_e_acompanhamento_no_banco_legado(): void
    {
        try {
            $this->comoNoLegado();
            (include database_path('migrations/2026_10_08_120000_allow_rector_role_on_admins_table.php'))->up();

            $this->dados();
            $admin = $this->usuario('admin', Admin::ROLE_ADMIN);

            $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'reitor', 'username' => 'novo.reitor', 'email' => 'novo@example.test'])
                ->assertRedirect(route('usuarios.index', ['aba' => 'reitores']));
            $this->assertTrue(Admin::where('username', 'novo.reitor')->firstOrFail()->ehReitor());
            $this->actingAs($admin, 'admin')->get('/usuarios?aba=reitores')->assertOk()->assertSee('novo.reitor');

            $coordenador = $this->usuario('coord', Admin::ROLE_COORDENADOR);
            $coordenador->sincronizarCursos(['DIREITO']);
            $aluno = Aluno::where('curso', 'DIREITO')->first();
            $this->actingAs($coordenador, 'admin')->post(route('coordenador.alunos.acompanhamento', $aluno), ['status' => 'contatado', 'observacao' => 'ok'])
                ->assertRedirect();
            $this->actingAs($coordenador, 'admin')->get(route('coordenador.alunos', ['acompanhamento' => 'contatado']))->assertOk()->assertSee($aluno->nome);
        } finally {
            $this->restaurar();
        }
    }
}
