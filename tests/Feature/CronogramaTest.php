<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Atividade;
use App\Models\CronogramaItem;
use App\Models\CronogramaPendencia;
use App\Models\Curso;
use App\Services\CronogramaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cronograma de atividades (checklist de auditoria ROC/ROD): o colaborador cadastra as atividades e indica os cursos —
 * o que monta o calendário de cada coordenador — e registra as pendências, que o coordenador só lê.
 */
class CronogramaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 10:00:00');
        foreach (['DIREITO', 'MEDICINA', 'ENFERMAGEM'] as $curso) {
            Curso::create(['nome' => $curso]);
        }
    }

    private function usuario(string $nome, string $role): Admin
    {
        return Admin::create(['username' => $nome, 'email' => "{$nome}@example.test", 'password_hash' => Hash::make('senha-secreta-123'), 'role' => $role]);
    }

    private ?Admin $colaborador = null;

    private function colaborador(): Admin
    {
        return $this->colaborador ??= $this->usuario('colab', Admin::ROLE_COLABORADOR);
    }

    private function coordenador(string $nome, string $curso): Admin
    {
        $coordenador = $this->usuario($nome, Admin::ROLE_COORDENADOR);
        $coordenador->sincronizarCursos([$curso]);

        return $coordenador;
    }

    /** @param  array<int, string>  $cursos */
    private function atividade(string $projeto, string $data, array $cursos, string $rotina = 'ROD'): CronogramaItem
    {
        $resposta = $this->actingAs($this->colaborador(), 'admin')->post(route('colaborador.atividades.store'), [
            'data' => $data, 'rotina' => $rotina, 'projeto' => $projeto, 'descricao' => "Conferir {$projeto}", 'cursos' => $cursos,
        ]);
        $resposta->assertSessionHasNoErrors();

        return CronogramaItem::where('projeto', $projeto)->firstOrFail();
    }

    // ---- perfil ----

    public function test_perfil_normaliza_e_comeca_no_cronograma(): void
    {
        $colaborador = new Admin(['role' => ' Collaborator ']);

        $this->assertTrue($colaborador->ehColaborador());
        $this->assertFalse($colaborador->ehAdministrador());
        $this->assertFalse($colaborador->ehCoordenador());
        $this->assertSame('colaborador.index', $colaborador->rotaInicial());
        $this->assertTrue($colaborador->temPapelValido());
    }

    public function test_administrador_cria_colaborador_que_entra_por_codigo(): void
    {
        $admin = $this->usuario('admin', Admin::ROLE_ADMIN);

        $this->actingAs($admin, 'admin')->post('/usuarios', ['papel' => 'colaborador', 'username' => 'maria', 'email' => 'maria@example.test'])
            ->assertRedirect(route('usuarios.index', ['aba' => 'colaboradores']));

        $maria = Admin::where('username', 'maria')->firstOrFail();
        $this->assertTrue($maria->ehColaborador());
        $this->assertSame([$maria->id], Admin::colaboradores()->pluck('id')->all());
        $this->assertSame([$maria->id], Admin::entramPorCodigo()->pluck('id')->all());
        $this->assertTrue(Atividade::where('acao', 'colaborador.criado')->exists());

        $this->get('/usuarios?aba=colaboradores')->assertOk()->assertSee('maria')->assertSee('Novo colaborador');
    }

    public function test_colaborador_sem_email_e_recusado(): void
    {
        $this->actingAs($this->usuario('admin', Admin::ROLE_ADMIN), 'admin')
            ->post('/usuarios', ['papel' => 'colaborador', 'username' => 'maria'])
            ->assertSessionHasErrors('email');
    }

    public function test_colaborador_so_alcanca_o_cronograma_e_o_perfil(): void
    {
        $this->actingAs($this->colaborador(), 'admin');

        $this->get('/')->assertRedirect(route('colaborador.index'));
        $this->get(route('colaborador.index'))->assertOk()->assertSee('Cronograma de atividades');
        $this->get(route('perfil.edit'))->assertOk();

        // Nada de avaliação, aluno ou gestão: GET nas telas do coordenador volta para o cronograma; o resto é 403.
        $this->get('/avaliacoes')->assertRedirect(route('colaborador.index'));
        $this->get('/painel')->assertRedirect(route('colaborador.index'));
        $this->get('/usuarios')->assertForbidden();
        $this->get('/alunos')->assertForbidden();
        $this->get('/reitoria')->assertRedirect(route('colaborador.index'));
    }

    // ---- cadastro e calendário ----

    public function test_atividade_monta_o_calendario_so_dos_cursos_marcados(): void
    {
        $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->atividade('Simulado Enfermagem', '2026-10-14', ['ENFERMAGEM']);

        $direito = $this->coordenador('coord-direito', 'DIREITO');
        $enfermagem = $this->coordenador('coord-enf', 'ENFERMAGEM');

        $html = $this->actingAs($direito, 'admin')->get(route('cronograma.index', ['mes' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('TIN', $html);
        $this->assertStringNotContainsString('Simulado Enfermagem', $html);

        $html = $this->actingAs($enfermagem, 'admin')->get(route('cronograma.index', ['mes' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('Simulado Enfermagem', $html);
        $this->assertStringNotContainsString('>TIN<', $html);
        $this->assertStringNotContainsString('TIN</span>', $html);
    }

    public function test_calendario_mostra_so_o_mes_pedido_e_o_mes_atual_por_padrao(): void
    {
        $this->atividade('Prova de outubro', '2026-10-20', ['DIREITO']);
        $this->atividade('Prova de dezembro', '2026-12-11', ['DIREITO']);
        $coordenador = $this->coordenador('coord', 'DIREITO');

        $padrao = $this->actingAs($coordenador, 'admin')->get(route('cronograma.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Outubro de 2026', $padrao);
        $this->assertStringContainsString('Prova de outubro', $padrao);
        $this->assertStringNotContainsString('Prova de dezembro', $padrao);

        $dezembro = $this->get(route('cronograma.index', ['mes' => '2026-12']))->assertOk()->getContent();
        $this->assertStringContainsString('Dezembro de 2026', $dezembro);
        $this->assertStringContainsString('Prova de dezembro', $dezembro);

        // mês inválido não quebra: cai no atual
        $this->get(route('cronograma.index', ['mes' => '2026-13']))->assertOk()->assertSee('Outubro de 2026');
    }

    public function test_atividade_de_outro_curso_e_404_para_o_coordenador(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['MEDICINA']);

        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin')->get(route('cronograma.show', $item))->assertNotFound();
        $this->actingAs($this->coordenador('coord-med', 'MEDICINA'), 'admin')->get(route('cronograma.show', $item))->assertOk()->assertSee('TIN');
    }

    public function test_coordenador_nao_cria_nem_altera_nada(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $this->actingAs($coordenador, 'admin');

        $this->post(route('colaborador.atividades.store'), ['data' => '2026-10-20', 'rotina' => 'ROD', 'projeto' => 'X', 'descricao' => 'X', 'cursos' => ['DIREITO']])->assertForbidden();
        $this->put(route('colaborador.atividades.update', $item), ['data' => '2026-10-20', 'rotina' => 'ROD', 'projeto' => 'Y', 'descricao' => 'Y', 'cursos' => ['DIREITO']])->assertForbidden();
        $this->put(route('colaborador.atividades.situacao', $item), ['status' => [1 => 'resolvido']])->assertForbidden();
        $this->delete(route('colaborador.atividades.destroy', $item))->assertForbidden();
        $this->post(route('colaborador.pendencias.store', $item), ['curso' => 'DIREITO', 'data' => '2026-10-08', 'pendencia' => 'x', 'status' => 'pendente'])->assertForbidden();
        $this->get(route('colaborador.index'))->assertForbidden();

        $this->assertSame('TIN', $item->fresh()->projeto);
        $this->assertSame(1, CronogramaItem::count());
    }

    public function test_valida_o_cadastro_da_atividade(): void
    {
        $this->actingAs($this->colaborador(), 'admin');
        $valido = ['data' => '2026-10-13', 'rotina' => 'ROD', 'projeto' => 'TIN', 'descricao' => 'Conferir', 'cursos' => ['DIREITO']];

        $this->post(route('colaborador.atividades.store'), [...$valido, 'cursos' => []])->assertSessionHasErrors('cursos');
        $this->post(route('colaborador.atividades.store'), [...$valido, 'cursos' => ['CURSO QUE NAO EXISTE']])->assertSessionHasErrors('cursos.0');
        $this->post(route('colaborador.atividades.store'), [...$valido, 'rotina' => 'XYZ'])->assertSessionHasErrors('rotina');
        $this->post(route('colaborador.atividades.store'), [...$valido, 'data' => '13/10/2026'])->assertSessionHasErrors('data');
        $this->post(route('colaborador.atividades.store'), [...$valido, 'projeto' => ''])->assertSessionHasErrors('projeto');
        $this->assertSame(0, CronogramaItem::count());
    }

    public function test_edita_atividade_mantem_a_situacao_dos_cursos_que_ficam(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->put(route('colaborador.atividades.situacao', $item), ['status' => $item->cursos->pluck('status', 'id')->map(fn () => 'resolvido')->all()]);

        $this->put(route('colaborador.atividades.update', $item), [
            'data' => '2026-10-15', 'rotina' => 'ROC', 'projeto' => 'TIN 2', 'descricao' => 'Novo texto', 'cursos' => ['DIREITO', 'ENFERMAGEM'],
        ])->assertRedirect(route('colaborador.atividades.show', $item));

        $item = $item->fresh()->load('cursos');
        $this->assertSame('2026-10-15', $item->data->toDateString());
        $this->assertSame('ROC', $item->rotina);
        $this->assertSame(['resolvido', 'aguardando'], $item->cursos->pluck('status')->all(), 'DIREITO continua resolvido; ENFERMAGEM entra como aguardando');
        $this->assertSame(['DIREITO', 'ENFERMAGEM'], $item->cursos->pluck('curso')->all(), 'MEDICINA saiu');
        $this->assertTrue(Atividade::where('acao', 'cronograma.atividade_editada')->exists());
    }

    public function test_atualiza_a_situacao_dos_cursos_e_ignora_ids_de_outra_atividade(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $outra = $this->atividade('P1', '2026-10-16', ['DIREITO']);
        [$direito, $medicina] = $item->cursos->all();

        $this->put(route('colaborador.atividades.situacao', $item), ['status' => [
            $direito->id => 'pendente', $medicina->id => 'em_acompanhamento', $outra->cursos->first()->id => 'resolvido',
        ]])->assertRedirect();

        $this->assertSame('pendente', $direito->fresh()->status);
        $this->assertSame('em_acompanhamento', $medicina->fresh()->status);
        $this->assertSame('aguardando', $outra->cursos->first()->fresh()->status, 'só mexe nos cursos desta atividade');

        $this->put(route('colaborador.atividades.situacao', $item), ['status' => [$direito->id => 'inventado']])->assertSessionHasErrors('status.*');
        $this->assertSame('pendente', $direito->fresh()->status);
    }

    public function test_coordenador_com_varios_cursos_ve_a_situacao_mais_urgente(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        [$direito, $medicina] = $item->cursos->all();
        $this->put(route('colaborador.atividades.situacao', $item), ['status' => [$direito->id => 'resolvido', $medicina->id => 'pendente']]);

        $this->assertSame('pendente', $item->fresh()->load('cursos')->resumoStatus());
        $this->assertSame('resolvido', $item->fresh()->load('cursos')->resumoStatus(['DIREITO']));
        $this->assertNull($item->fresh()->load('cursos')->resumoStatus(['ENFERMAGEM']));
    }

    // ---- pendências ----

    private function pendencia(CronogramaItem $item, string $curso, array $extra = []): void
    {
        $this->actingAs($this->colaborador(), 'admin')->post(route('colaborador.pendencias.store', $item), [
            'curso' => $curso, 'data' => '2026-10-09', 'pendencia' => '2 provas pendentes', 'encaminhamento' => 'Coordenação acionada',
            'prazo' => '2026-10-12', 'status' => 'pendente', 'responsavel' => 'Millena', ...$extra,
        ])->assertSessionHasNoErrors();
    }

    public function test_pendencia_fica_vinculada_e_o_coordenador_so_le_a_do_curso_dele(): void
    {
        $item = $this->atividade('A2 - Módulo A', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->pendencia($item, 'DIREITO', ['pendencia' => 'Faltam provas do Direito']);
        $this->pendencia($item, 'MEDICINA', ['pendencia' => 'Faltam provas da Medicina']);

        $registro = CronogramaPendencia::where('curso', 'DIREITO')->firstOrFail();
        $this->assertSame($item->id, $registro->item_id);
        $this->assertSame('Millena', $registro->responsavel);
        $this->assertNotNull($registro->registrado_por);

        $coordenador = $this->coordenador('coord', 'DIREITO');
        $index = $this->actingAs($coordenador, 'admin')->get(route('cronograma.index', ['mes' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('Faltam provas do Direito', $index);
        $this->assertStringNotContainsString('Faltam provas da Medicina', $index);

        $detalhe = $this->get(route('cronograma.show', $item))->assertOk()->getContent();
        $this->assertStringContainsString('Faltam provas do Direito', $detalhe);
        $this->assertStringNotContainsString('Faltam provas da Medicina', $detalhe);
        // Só leitura: nenhuma ação de gravar na tela do coordenador.
        $this->assertStringNotContainsString('Registrar pendência', $detalhe);
        $this->assertStringNotContainsString(route('colaborador.pendencias.store', $item), $detalhe);
    }

    public function test_pendencia_so_vale_para_curso_da_atividade(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);

        $this->actingAs($this->colaborador(), 'admin')->post(route('colaborador.pendencias.store', $item), [
            'curso' => 'MEDICINA', 'data' => '2026-10-09', 'pendencia' => 'x', 'status' => 'pendente',
        ])->assertSessionHasErrors('curso');

        $this->post(route('colaborador.pendencias.store', $item), ['curso' => 'DIREITO', 'data' => '2026-10-09', 'pendencia' => '', 'status' => 'pendente'])->assertSessionHasErrors('pendencia');
        $this->post(route('colaborador.pendencias.store', $item), ['curso' => 'DIREITO', 'data' => '2026-10-09', 'pendencia' => 'x', 'status' => 'pendente', 'prazo' => '2026-10-01'])->assertSessionHasErrors('prazo');
        $this->assertSame(0, CronogramaPendencia::count());
    }

    public function test_resolver_marca_a_data_e_reabrir_limpa(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $this->pendencia($item, 'DIREITO');
        $registro = CronogramaPendencia::firstOrFail();
        $this->assertNull($registro->resolvida_em);

        $corpo = ['pendencia' => 'Resolvida em reunião', 'encaminhamento' => 'Feito', 'prazo' => '2026-10-12', 'responsavel' => 'Millena'];

        $this->put(route('colaborador.pendencias.update', $registro), [...$corpo, 'status' => 'resolvido'])->assertRedirect(route('colaborador.atividades.show', $item));
        $registro->refresh();
        $this->assertSame('resolvido', $registro->status);
        $this->assertSame('2026-10-08', $registro->resolvida_em->toDateString());
        $this->assertSame('DIREITO', $registro->curso, 'o curso do registro não muda');
        $this->assertSame('2026-10-09', $registro->data->toDateString(), 'a data do registro não muda');

        $this->put(route('colaborador.pendencias.update', $registro), [...$corpo, 'status' => 'pendente']);
        $this->assertNull($registro->fresh()->resolvida_em);
        $this->assertTrue(Atividade::where('acao', 'cronograma.pendencia_atualizada')->exists());
    }

    public function test_atividade_com_pendencia_nao_e_excluida_nem_perde_o_curso(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->pendencia($item, 'DIREITO');
        $this->actingAs($this->colaborador(), 'admin');

        $this->delete(route('colaborador.atividades.destroy', $item))->assertSessionHasErrors('item');
        $this->assertSame(1, CronogramaItem::count());

        $this->put(route('colaborador.atividades.update', $item), [
            'data' => '2026-10-13', 'rotina' => 'ROD', 'projeto' => 'TIN', 'descricao' => 'x', 'cursos' => ['MEDICINA'],
        ])->assertSessionHasErrors('cursos');
        $this->assertSame(['DIREITO', 'MEDICINA'], $item->fresh()->cursos->pluck('curso')->all());

        // Sem pendência, a exclusão funciona e fica na auditoria.
        $livre = $this->atividade('P1', '2026-10-16', ['DIREITO']);
        $this->delete(route('colaborador.atividades.destroy', $livre))->assertRedirect();
        $this->assertNull(CronogramaItem::find($livre->id));
        $this->assertTrue(Atividade::where('acao', 'cronograma.atividade_excluida')->exists());
    }

    public function test_lista_de_pendencias_filtra_por_situacao_e_curso(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->pendencia($item, 'DIREITO', ['pendencia' => 'Aberta do Direito']);
        $this->pendencia($item, 'MEDICINA', ['pendencia' => 'Resolvida da Medicina', 'status' => 'resolvido']);

        $this->actingAs($this->colaborador(), 'admin');
        $abertas = $this->get(route('colaborador.pendencias.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Aberta do Direito', $abertas);
        $this->assertStringNotContainsString('Resolvida da Medicina', $abertas);

        $todas = $this->get(route('colaborador.pendencias.index', ['status' => 'todas']))->getContent();
        $this->assertStringContainsString('Resolvida da Medicina', $todas);

        $medicina = $this->get(route('colaborador.pendencias.index', ['status' => 'todas', 'curso' => 'MEDICINA']))->getContent();
        $this->assertStringContainsString('Resolvida da Medicina', $medicina);
        $this->assertStringNotContainsString('Aberta do Direito', $medicina);
    }

    public function test_resumo_conta_abertas_e_atrasadas(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $this->pendencia($item, 'DIREITO', ['prazo' => '2026-10-12']); // hoje é 08/10: no prazo
        $this->travelTo('2026-10-20 10:00:00');
        $this->pendencia($item, 'DIREITO', ['data' => '2026-10-09', 'prazo' => '2026-10-15', 'pendencia' => 'Outra']); // vencida

        $resumo = app(CronogramaService::class)->resumoPendencias(['DIREITO']);
        $this->assertSame(['abertas' => 2, 'atrasadas' => 2], $resumo);
        $this->assertSame(['abertas' => 0, 'atrasadas' => 0], app(CronogramaService::class)->resumoPendencias(['MEDICINA']));
    }

    // ---- reitor e administrador ----

    public function test_reitor_na_visao_de_um_curso_ve_o_cronograma_dele_sem_poder_gravar(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $outra = $this->atividade('Só Medicina', '2026-10-14', ['MEDICINA']);
        $this->pendencia($item, 'DIREITO');
        $reitor = $this->usuario('reitor', Admin::ROLE_REITOR);

        $this->actingAs($reitor, 'admin')->get(route('reitor.curso.abrir', ['curso' => 'DIREITO']))->assertRedirect();

        $html = $this->get(route('cronograma.index', ['mes' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('TIN', $html);
        $this->assertStringNotContainsString('Só Medicina', $html);
        $this->get(route('cronograma.show', $outra))->assertNotFound();
        $this->post(route('colaborador.pendencias.store', $item), ['curso' => 'DIREITO', 'data' => '2026-10-09', 'pendencia' => 'x', 'status' => 'pendente'])->assertForbidden();
    }

    public function test_reitor_fora_da_visao_nao_acessa_a_gestao_do_cronograma(): void
    {
        $this->actingAs($this->usuario('reitor', Admin::ROLE_REITOR), 'admin')->get(route('colaborador.index'))->assertRedirect(route('reitor.visao'));
    }

    public function test_administrador_gerencia_o_cronograma_e_e_levado_a_tela_de_gestao(): void
    {
        $this->actingAs($this->usuario('admin', Admin::ROLE_ADMIN), 'admin');

        $this->get(route('colaborador.index'))->assertOk();
        $this->get(route('cronograma.index'))->assertRedirect(route('colaborador.index'));
        $this->get(route('colaborador.atividades.create'))->assertOk();
    }

    public function test_perfil_desconhecido_nao_acessa_nada(): void
    {
        $this->actingAs($this->usuario('estranho', 'qualquer-coisa'), 'admin')->get(route('colaborador.index'))->assertRedirect(route('login'));
    }

    // ---- lista e filtros ----

    private function lista(string $consulta = ''): string
    {
        return $this->get(route('cronograma.index').'?visao=lista'.($consulta !== '' ? '&'.$consulta : ''))->assertOk()->getContent();
    }

    public function test_lista_traz_so_as_atividades_dos_cursos_do_coordenador_em_ordem_de_data(): void
    {
        $this->atividade('Segunda prova', '2026-11-05', ['DIREITO']);
        $this->atividade('Primeira prova', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->atividade('Só Medicina', '2026-10-14', ['MEDICINA']);

        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin');
        $html = $this->lista();

        $this->assertStringContainsString('2 encontradas', $html);
        $this->assertStringNotContainsString('Só Medicina', $html);
        $this->assertLessThan(strpos($html, 'Segunda prova'), strpos($html, 'Primeira prova'), 'da data mais antiga para a mais recente');
        // sem o mês: a lista atravessa meses (o calendário mostraria um de cada vez)
        $this->assertStringContainsString('13/10/2026', $html);
        $this->assertStringContainsString('05/11/2026', $html);
        // a tela oferece a troca de visão e aponta a atual
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*<i[^>]*><\/i> Lista/', $html);
    }

    public function test_filtros_da_lista_busca_rotina_situacao_e_datas(): void
    {
        $tin = $this->atividade('TIN', '2026-10-13', ['DIREITO'], 'ROD');
        $this->atividade('Simulado', '2026-10-20', ['DIREITO'], 'ROC');
        $this->atividade('Prova final', '2026-12-01', ['DIREITO'], 'Auditoria');
        $this->put(route('colaborador.atividades.situacao', $tin), ['status' => [$tin->cursos->first()->id => 'pendente']]);
        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin');

        $achados = fn (string $consulta) => collect(['TIN', 'Simulado', 'Prova final'])->filter(fn ($n) => str_contains($this->lista($consulta), $n))->values()->all();

        $this->assertSame(['TIN', 'Simulado', 'Prova final'], $achados(''));
        $this->assertSame(['Simulado'], $achados('busca=simul'));
        $this->assertSame(['Simulado'], $achados('busca=Conferir+Simulado'), 'busca também na descrição');
        $this->assertSame(['Prova final'], $achados('rotina=Auditoria'));
        $this->assertSame(['TIN'], $achados('status=pendente'));
        $this->assertSame(['Simulado', 'Prova final'], $achados('de=2026-10-15'));
        $this->assertSame(['TIN', 'Simulado'], $achados('ate=2026-10-31'));
        $this->assertSame(['Simulado'], $achados('de=2026-10-15&ate=2026-10-31'));
        $this->assertSame([], $achados('busca=nada+disso'));
        // valores inválidos são ignorados, não quebram
        $this->assertSame(['TIN', 'Simulado', 'Prova final'], $achados('rotina=XYZ&status=inventado&de=ontem&ate=amanha'));
        // % na busca vale como texto, não como curinga
        $this->assertSame([], $achados('busca=%25'));
    }

    public function test_filtro_de_situacao_vale_para_os_cursos_do_proprio_coordenador(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        [$direito, $medicina] = $item->cursos->all();
        $this->put(route('colaborador.atividades.situacao', $item), ['status' => [$direito->id => 'resolvido', $medicina->id => 'pendente']]);

        // O curso de Medicina está pendente, mas o coordenador de Direito (resolvido) não deve achar a atividade como pendente.
        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin');
        $this->assertStringContainsString('Nenhuma atividade encontrada', $this->lista('status=pendente'));
        $this->assertStringContainsString('TIN', $this->lista('status=resolvido'));
    }

    public function test_coordenador_com_dois_cursos_filtra_por_curso_na_lista_e_no_calendario(): void
    {
        $this->atividade('Só Direito', '2026-10-13', ['DIREITO']);
        $this->atividade('Só Medicina', '2026-10-14', ['MEDICINA']);
        $this->atividade('Só Enfermagem', '2026-10-15', ['ENFERMAGEM']);
        $coordenador = $this->coordenador('coord', 'DIREITO');
        $coordenador->sincronizarCursos(['DIREITO', 'MEDICINA']);
        $this->actingAs($coordenador, 'admin');

        $todas = $this->lista();
        $this->assertStringContainsString('Só Direito', $todas);
        $this->assertStringContainsString('Só Medicina', $todas);
        $this->assertStringNotContainsString('Só Enfermagem', $todas);

        $direito = $this->lista('curso=DIREITO');
        $this->assertStringContainsString('Só Direito', $direito);
        $this->assertStringNotContainsString('Só Medicina', $direito);

        $calendario = $this->get(route('cronograma.index', ['mes' => '2026-10', 'curso' => 'MEDICINA', 'busca' => 'Medicina']))->getContent();
        $this->assertStringContainsString('Só Medicina', $calendario);
        $this->assertStringNotContainsString('Só Direito', $calendario);

        // curso que não é dele é ignorado (volta a "todos os meus cursos"), nunca amplia o acesso
        $this->assertStringNotContainsString('Só Enfermagem', $this->lista('curso=ENFERMAGEM'));
    }

    public function test_filtros_tambem_valem_no_calendario(): void
    {
        $this->atividade('Prova ROD', '2026-10-13', ['DIREITO'], 'ROD');
        $this->atividade('Prova ROC', '2026-10-14', ['DIREITO'], 'ROC');
        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin');

        $html = $this->get(route('cronograma.index', ['mes' => '2026-10', 'rotina' => 'ROC']))->getContent();
        $this->assertStringContainsString('Prova ROC', $html);
        $this->assertStringNotContainsString('Prova ROD', $html);
    }

    public function test_lista_e_paginada(): void
    {
        foreach (range(1, 22) as $n) {
            $this->atividade(sprintf('Atividade %02d', $n), '2026-10-'.sprintf('%02d', $n), ['DIREITO']);
        }
        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin');

        $primeira = $this->lista();
        $this->assertStringContainsString('22 encontradas', $primeira);
        $this->assertStringContainsString('Atividade 20', $primeira);
        $this->assertStringNotContainsString('Atividade 21', $primeira);

        $segunda = $this->get(route('cronograma.index', ['visao' => 'lista', 'p' => 2]))->getContent();
        $this->assertStringContainsString('Atividade 21', $segunda);
    }

    public function test_colaborador_ve_a_lista_de_todas_as_atividades_com_pendencias_abertas(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO', 'MEDICINA']);
        $this->atividade('Só Enfermagem', '2026-10-14', ['ENFERMAGEM']);
        $this->pendencia($item, 'DIREITO');
        $this->pendencia($item, 'MEDICINA', ['status' => 'resolvido']);

        $html = $this->actingAs($this->colaborador(), 'admin')->get(route('colaborador.index', ['visao' => 'lista']))->assertOk()->getContent();
        $this->assertStringContainsString('2 encontradas', $html);
        $this->assertStringContainsString('Só Enfermagem', $html);
        $this->assertMatchesRegularExpression('/ph-warning"[^>]*><\/i>1<\/span>/', $html, 'só a pendência aberta conta');

        $filtrado = $this->get(route('colaborador.index', ['visao' => 'lista', 'curso' => 'ENFERMAGEM']))->getContent();
        $this->assertStringContainsString('1 encontrada', $filtrado);
        $this->assertStringNotContainsString('Conferir TIN', $filtrado);
    }

    // ---- excluir pendência ----

    public function test_colaborador_exclui_pendencia_e_o_conteudo_fica_na_auditoria(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $this->pendencia($item, 'DIREITO', ['pendencia' => 'Lançada por engano']);
        $registro = CronogramaPendencia::firstOrFail();

        $this->delete(route('colaborador.pendencias.destroy', $registro))->assertRedirect(route('colaborador.atividades.show', $item));

        $this->assertSame(0, CronogramaPendencia::count());
        $log = Atividade::where('acao', 'cronograma.pendencia_excluida')->firstOrFail();
        $this->assertStringContainsString('Lançada por engano', json_encode($log->detalhes, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, CronogramaItem::count(), 'a atividade continua');

        // sem pendências, a atividade volta a poder ser excluída
        $this->delete(route('colaborador.atividades.destroy', $item))->assertRedirect();
        $this->assertSame(0, CronogramaItem::count());
    }

    public function test_so_quem_gerencia_exclui_pendencia(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $this->pendencia($item, 'DIREITO');
        $registro = CronogramaPendencia::firstOrFail();

        $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin')->delete(route('colaborador.pendencias.destroy', $registro))->assertForbidden();
        $this->actingAs($this->usuario('reitor', Admin::ROLE_REITOR), 'admin')->delete(route('colaborador.pendencias.destroy', $registro))->assertForbidden();
        $this->assertSame(1, CronogramaPendencia::count());

        $this->actingAs($this->usuario('admin', Admin::ROLE_ADMIN), 'admin')->delete(route('colaborador.pendencias.destroy', $registro))->assertRedirect();
        $this->assertSame(0, CronogramaPendencia::count());
    }

    public function test_tela_da_atividade_oferece_excluir_pendencia_so_ao_colaborador(): void
    {
        $item = $this->atividade('TIN', '2026-10-13', ['DIREITO']);
        $this->pendencia($item, 'DIREITO');
        $registro = CronogramaPendencia::firstOrFail();

        $colaborador = $this->actingAs($this->colaborador(), 'admin')->get(route('colaborador.atividades.show', $item))->getContent();
        $this->assertStringContainsString(route('colaborador.pendencias.destroy', $registro), $colaborador);

        $coordenador = $this->actingAs($this->coordenador('coord', 'DIREITO'), 'admin')->get(route('cronograma.show', $item))->getContent();
        $this->assertStringNotContainsString(route('colaborador.pendencias.destroy', $registro), $coordenador);
    }
}
