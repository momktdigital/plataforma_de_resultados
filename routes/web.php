<?php

use App\Http\Controllers\Admin\AlunoController;
use App\Http\Controllers\Admin\AvaliacaoController;
use App\Http\Controllers\Admin\AvaliacaoVisualizacaoController;
use App\Http\Controllers\Admin\BiController;
use App\Http\Controllers\Admin\BiListaController;
use App\Http\Controllers\Admin\BuscaGlobalController;
use App\Http\Controllers\Admin\CategoriaController;
use App\Http\Controllers\Admin\CoordenadorAcompanhamentoController;
use App\Http\Controllers\Admin\CoordenadorAlunosController;
use App\Http\Controllers\Admin\CoordenadorComparativoController;
use App\Http\Controllers\Admin\ColaboradorCronogramaController;
use App\Http\Controllers\Admin\ColaboradorPlanoAcaoController;
use App\Http\Controllers\Admin\ColaboradorPendenciaController;
use App\Http\Controllers\Admin\CoordenadorController;
use App\Http\Controllers\Admin\CoordenadorPlanoAcaoController;
use App\Http\Controllers\Admin\CoordenadorPlanoExecucaoController;
use App\Http\Controllers\Admin\CronogramaController;
use App\Http\Controllers\Admin\LixeiraController;
use App\Http\Controllers\Admin\NotificacaoController;
use App\Http\Controllers\Admin\ReitorController;
use App\Http\Controllers\Admin\ReitorCursoController;
use App\Http\Controllers\Admin\MatriculaImportController;
use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\QuestaoController;
use App\Http\Controllers\Admin\QuestaoExportController;
use App\Http\Controllers\Admin\QuestaoImportController;
use App\Http\Controllers\Admin\RespondenteController;
use App\Http\Controllers\Admin\ResultadoImportController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginCodigoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\Sistema\AtividadeController;
use App\Http\Controllers\Sistema\AtualizacaoController;
use App\Http\Controllers\Sistema\BackupController;
use App\Http\Controllers\Sistema\ConfiguracaoController;
use App\Http\Controllers\Sistema\LegadoController;
use App\Http\Controllers\Sistema\PortalConfiguracaoController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('nao-instalado')->prefix('instalar')->name('instalar.')->group(function () {
    Route::get('/', [InstallController::class, 'inicio'])->name('inicio');
    Route::get('/banco', [InstallController::class, 'formularioBanco'])->name('banco');
    Route::post('/banco', [InstallController::class, 'testarEGravarBanco'])->name('banco.gravar');
    Route::get('/migrar', [InstallController::class, 'confirmarMigracao'])->name('migrar');
    Route::post('/migrar', [InstallController::class, 'migrar'])->name('migrar.store');
    Route::get('/admin', [InstallController::class, 'formularioAdmin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'criarAdmin'])->name('admin.criar');
});

Route::middleware('instalado')->group(function () {
    Route::get('/', function () {
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('portal.consulta');
        }

        return redirect()->route(Auth::guard('admin')->user()->rotaInicial());
    });

    Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/', [PortalController::class, 'mostrarConsulta'])->name('consulta');

        // Só aqui tem algo pra adivinhar (CPF+nascimento, código de 2FA) —
        // throttle apertado, na mesma ordem de grandeza do login. As rotas
        // de navegação abaixo (GET) não compartilham esse orçamento: um
        // laboratório inteiro abrindo o próprio boletim ao mesmo tempo não
        // deveria consumir o mesmo limite que tentar adivinhar CPF de outro
        // aluno — nem travar essa navegação legítima nem, ao contrário,
        // afrouxar o limite de quem está de fato tentando adivinhar.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/consultar', [PortalController::class, 'consultar'])->name('consultar');
            Route::post('/verificar', [PortalController::class, 'verificar'])->name('verificar');
            Route::post('/reenviar', [PortalController::class, 'reenviar'])->name('reenviar');
        });

        // Sem throttle: exigem sessão de aluno já autenticado (redirecionam
        // pra consulta.index sem tocar o banco quando não há), então não há
        // nada de "adivinhável" pra limitar aqui.
        Route::get('/resultados', [PortalController::class, 'resultados'])->name('resultados');
        Route::get('/resultados/avaliacoes/{avaliacao}', [PortalController::class, 'resultadoAvaliacao'])->name('resultados.avaliacao');
        Route::get('/sair', [PortalController::class, 'sair'])->name('sair');
    });

    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        // Defesa em profundidade, não redundância: este throttle:10,1 é por
        // IP (bloqueia flood na rota inteira); o LoginController também tem
        // o seu próprio RateLimiter, de 5 tentativas/60s por usuário+IP
        // (bloqueia tentativas contra uma conta específica sem exigir que o
        // atacante sature a rota toda). Ver LoginController::throttleKey().
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.attempt');

        // Coordenador: entra com um código enviado por e-mail, sem senha (administrador
        // continua com usuário e senha — a senha só é obrigatória para ele).
        Route::get('/login/codigo', [LoginCodigoController::class, 'formulario'])->name('login.codigo.form');
        Route::post('/login/codigo', [LoginCodigoController::class, 'solicitar'])
            ->middleware('throttle:5,1')
            ->name('login.codigo.solicitar');
        Route::post('/login/codigo/verificar', [LoginCodigoController::class, 'verificar'])
            ->middleware('throttle:10,1')
            ->name('login.codigo.verificar');
        Route::post('/login/codigo/reenviar', [LoginCodigoController::class, 'reenviar'])
            ->middleware('throttle:5,1')
            ->name('login.codigo.reenviar');

        Route::get('/esqueci-senha', [ForgotPasswordController::class, 'create'])->name('senha.esqueci');
        Route::post('/esqueci-senha', [ForgotPasswordController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('senha.esqueci.enviar');
        Route::get('/redefinir-senha/{token}', [ForgotPasswordController::class, 'edit'])->name('senha.redefinir.edit');
        Route::post('/redefinir-senha/{token}', [ForgotPasswordController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('senha.redefinir.salvar');
    });

    // `papel-valido`: conta com `role` desconhecido tem a sessão encerrada (ver PapelValido).
    Route::middleware(['auth:admin', 'papel-valido'])->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        // `visao-de-curso` ANTES de `perfil`: o reitor com um curso escolhido entra aqui como coordenador DAQUELE curso.
        Route::middleware(['visao-de-curso', 'perfil:administrador,coordenador'])->group(function () {
            // Acessível a administradores E coordenadores. Para o coordenador,
            // a listagem e o BI já filtram/validam pelos cursos dele (ver
            // AvaliacaoController::index e BiController::index).
            Route::get('/painel', [CoordenadorController::class, 'painel'])->name('coordenador.painel');
            Route::get('/painel/desempenho', [CoordenadorController::class, 'desempenho'])->name('coordenador.desempenho');
            Route::get('/painel/comparativo', [CoordenadorComparativoController::class, 'index'])->name('coordenador.comparativo');
            Route::get('/painel/alunos', [CoordenadorAlunosController::class, 'index'])->name('coordenador.alunos');
            // Segmento literal ANTES do coringa {aluno} — senão o coringa casa com "exportar.xlsx" primeiro.
            Route::get('/painel/alunos/exportar.xlsx', [CoordenadorAlunosController::class, 'xlsx'])->name('coordenador.alunos.xlsx');
            Route::get('/painel/alunos/{aluno}', [CoordenadorAlunosController::class, 'show'])->whereNumber('aluno')->name('coordenador.alunos.show');
            Route::post('/painel/alunos/{aluno}/acompanhamento', [CoordenadorAcompanhamentoController::class, 'store'])->whereNumber('aluno')->name('coordenador.alunos.acompanhamento');
            // Planos de ação: o coordenador inicia a partir de um dado do painel, preenche o roteiro (dado → causa → ação), envia ao
            // colaborador e acompanha a execução. Segmento literal (`novo`) ANTES do coringa {plano}.
            Route::get('/painel/planos', [CoordenadorPlanoAcaoController::class, 'index'])->name('coordenador.planos.index');
            Route::get('/painel/planos/novo', [CoordenadorPlanoAcaoController::class, 'novo'])->name('coordenador.planos.novo');
            Route::post('/painel/planos', [CoordenadorPlanoAcaoController::class, 'store'])->name('coordenador.planos.store');
            Route::get('/painel/planos/{plano}', [CoordenadorPlanoAcaoController::class, 'show'])->whereNumber('plano')->name('coordenador.planos.show');
            Route::get('/painel/planos/{plano}/editar', [CoordenadorPlanoAcaoController::class, 'edit'])->whereNumber('plano')->name('coordenador.planos.edit');
            Route::put('/painel/planos/{plano}', [CoordenadorPlanoAcaoController::class, 'update'])->whereNumber('plano')->name('coordenador.planos.update');
            Route::delete('/painel/planos/{plano}', [CoordenadorPlanoAcaoController::class, 'destroy'])->whereNumber('plano')->name('coordenador.planos.destroy');
            Route::post('/painel/planos/{plano}/retirar', [CoordenadorPlanoAcaoController::class, 'retirar'])->whereNumber('plano')->name('coordenador.planos.retirar');
            Route::post('/painel/planos/{plano}/duplicar', [CoordenadorPlanoAcaoController::class, 'duplicar'])->whereNumber('plano')->name('coordenador.planos.duplicar');
            Route::put('/painel/planos/{plano}/acoes/{acao}', [CoordenadorPlanoExecucaoController::class, 'atualizarAcao'])->whereNumber(['plano', 'acao'])->name('coordenador.planos.acoes.update');
            Route::post('/painel/planos/{plano}/comentarios', [CoordenadorPlanoExecucaoController::class, 'comentar'])->whereNumber('plano')->name('coordenador.planos.comentarios.store');
            Route::post('/painel/planos/{plano}/encerrar', [CoordenadorPlanoExecucaoController::class, 'encerrar'])->whereNumber('plano')->name('coordenador.planos.encerrar');
            Route::post('/painel/planos/{plano}/cancelar', [CoordenadorPlanoExecucaoController::class, 'cancelar'])->whereNumber('plano')->name('coordenador.planos.cancelar');
            // Cronograma de atividades (somente leitura): o coordenador vê as atividades dos cursos dele e as pendências.
            Route::get('/cronograma', [CronogramaController::class, 'index'])->name('cronograma.index');
            Route::get('/cronograma/atividades/{item}', [CronogramaController::class, 'show'])->whereNumber('item')->name('cronograma.show');
            Route::get('/avaliacoes', [AvaliacaoController::class, 'index'])->name('avaliacoes.index');
            Route::get('/avaliacoes/{avaliacao}/bi', [BiController::class, 'index'])->name('avaliacoes.bi');
            Route::get('/avaliacoes/{avaliacao}/bi/alunos.xlsx', [BiListaController::class, 'xlsx'])->name('avaliacoes.bi.alunos.xlsx');
            Route::get('/avaliacoes/{avaliacao}/bi/alunos/linhas', [BiListaController::class, 'linhas'])->name('avaliacoes.bi.alunos.linhas');
            // Notificações do coordenador (cada um só enxerga as próprias; administrador volta para as avaliações).
            Route::get('/notificacoes', [NotificacaoController::class, 'index'])->name('notificacoes.index');
            Route::get('/notificacoes/resumo', [NotificacaoController::class, 'resumo'])->middleware('throttle:60,1')->name('notificacoes.resumo');
            Route::post('/notificacoes/lidas', [NotificacaoController::class, 'marcarTodasLidas'])->name('notificacoes.lidas');
            Route::get('/notificacoes/{notificacao}/abrir', [NotificacaoController::class, 'abrir'])->whereNumber('notificacao')->name('notificacoes.abrir');
            Route::post('/notificacoes/{notificacao}/lida', [NotificacaoController::class, 'marcarLida'])->whereNumber('notificacao')->name('notificacoes.lida');
        });

        // Painel da reitoria: indicadores AGREGADOS de todos os cursos (nunca dado nominal de aluno). O administrador
        // também entra, para ver o que o reitor vê; o coordenador não (o painel dele é o do curso).
        // Abrir/fechar a visão do coordenador de um curso (só o reitor; ver VisaoDeCursoDoReitor).
        Route::middleware('perfil:reitor')->prefix('reitoria')->name('reitor.')->group(function () {
            Route::get('/analise-do-curso', [ReitorController::class, 'cursos'])->name('cursos');
            Route::get('/curso', [ReitorCursoController::class, 'abrir'])->name('curso.abrir');
            Route::get('/curso/sair', [ReitorCursoController::class, 'sair'])->name('curso.sair');
        });

        Route::middleware('perfil:reitor,administrador')->prefix('reitoria')->name('reitor.')->group(function () {
            Route::get('/', [ReitorController::class, 'visao'])->name('visao');
            Route::get('/desempenho', [ReitorController::class, 'desempenho'])->name('desempenho');
            Route::get('/trajetoria', [ReitorController::class, 'trajetoria'])->name('trajetoria');
            Route::get('/competencias', [ReitorController::class, 'competencias'])->name('competencias');
            Route::get('/evolucao', [ReitorController::class, 'evolucao'])->name('evolucao');
            Route::get('/risco', [ReitorController::class, 'risco'])->name('risco');
            Route::get('/itens', [ReitorController::class, 'itens'])->name('itens');
            Route::get('/relatorio', [ReitorController::class, 'relatorio'])->name('relatorio');
            Route::get('/exportar.xlsx', [ReitorController::class, 'xlsx'])->name('xlsx');
        });

        // Cronograma de atividades: o colaborador (e o administrador) cadastra as atividades, indica os cursos e registra as
        // pendências. Segmento literal (`nova`) ANTES do coringa {item}.
        Route::middleware('perfil:colaborador,administrador')->prefix('colaboracao')->name('colaborador.')->group(function () {
            Route::get('/', [ColaboradorCronogramaController::class, 'index'])->name('index');
            Route::get('/atividades/nova', [ColaboradorCronogramaController::class, 'create'])->name('atividades.create');
            Route::post('/atividades', [ColaboradorCronogramaController::class, 'store'])->name('atividades.store');
            Route::get('/atividades/{item}', [ColaboradorCronogramaController::class, 'show'])->whereNumber('item')->name('atividades.show');
            Route::get('/atividades/{item}/editar', [ColaboradorCronogramaController::class, 'edit'])->whereNumber('item')->name('atividades.edit');
            Route::put('/atividades/{item}', [ColaboradorCronogramaController::class, 'update'])->whereNumber('item')->name('atividades.update');
            Route::put('/atividades/{item}/situacao', [ColaboradorCronogramaController::class, 'situacao'])->whereNumber('item')->name('atividades.situacao');
            Route::delete('/atividades/{item}', [ColaboradorCronogramaController::class, 'destroy'])->whereNumber('item')->name('atividades.destroy');
            // Planos de ação enviados pelos coordenadores: a fila de análise (aprovar, pedir ajustes, recusar — sempre com
            // justificativa) e o acompanhamento dos que estão em execução.
            Route::get('/planos', [ColaboradorPlanoAcaoController::class, 'index'])->name('planos.index');
            Route::get('/planos/{plano}', [ColaboradorPlanoAcaoController::class, 'show'])->whereNumber('plano')->name('planos.show');
            Route::post('/planos/{plano}/decisao', [ColaboradorPlanoAcaoController::class, 'decidir'])->whereNumber('plano')->name('planos.decidir');
            Route::post('/planos/{plano}/comentarios', [ColaboradorPlanoAcaoController::class, 'comentar'])->whereNumber('plano')->name('planos.comentar');
            Route::get('/pendencias', [ColaboradorPendenciaController::class, 'index'])->name('pendencias.index');
            Route::post('/atividades/{item}/pendencias', [ColaboradorPendenciaController::class, 'store'])->whereNumber('item')->name('pendencias.store');
            Route::put('/pendencias/{pendencia}', [ColaboradorPendenciaController::class, 'update'])->whereNumber('pendencia')->name('pendencias.update');
            Route::delete('/pendencias/{pendencia}', [ColaboradorPendenciaController::class, 'destroy'])->whereNumber('pendencia')->name('pendencias.destroy');
        });

        Route::redirect('/administradores', '/usuarios');
        Route::get('/perfil', [PerfilController::class, 'edit'])->name('perfil.edit');
        Route::put('/perfil/senha', [PerfilController::class, 'updateSenha'])->name('perfil.senha');

        // Todo o resto é só de administrador (coordenador recebe 403).
        Route::middleware('somente-admin')->group(function () {

        Route::get('/buscar', [BuscaGlobalController::class, 'index'])->name('busca.index');

        Route::get('/alunos', [AlunoController::class, 'index'])->name('alunos.index');
        Route::get('/alunos/importar', [MatriculaImportController::class, 'create'])->name('alunos.importar');
        Route::post('/alunos/importar', [MatriculaImportController::class, 'store'])->name('alunos.importar.store');
        Route::get('/alunos/novo', [AlunoController::class, 'create'])->name('alunos.create');
        Route::post('/alunos', [AlunoController::class, 'store'])->name('alunos.store');
        Route::get('/alunos/{aluno}/editar', [AlunoController::class, 'edit'])->name('alunos.edit');
        Route::put('/alunos/{aluno}', [AlunoController::class, 'update'])->name('alunos.update');
        Route::delete('/alunos/{aluno}', [AlunoController::class, 'destroy'])->name('alunos.destroy');

        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::get('/usuarios/{admin}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('/usuarios/{admin}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::delete('/usuarios/{admin}', [UsuarioController::class, 'destroy'])->name('usuarios.destroy');

        Route::get('/categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::get('/categorias/{categoria}/editar', [CategoriaController::class, 'edit'])->name('categorias.edit');
        Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');

        Route::post('/avaliacoes', [AvaliacaoController::class, 'store'])->name('avaliacoes.store');
        Route::get('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'show'])->name('avaliacoes.show');
        Route::put('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'update'])->name('avaliacoes.update');
        Route::delete('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'destroy'])->name('avaliacoes.destroy');

        Route::get('/avaliacoes/{avaliacao}/questoes/import', [QuestaoImportController::class, 'create'])
            ->name('avaliacoes.questoes.import');
        Route::post('/avaliacoes/{avaliacao}/questoes/import/preview', [QuestaoImportController::class, 'preview'])
            ->name('avaliacoes.questoes.import.preview');
        Route::post('/avaliacoes/{avaliacao}/questoes/import', [QuestaoImportController::class, 'store'])
            ->name('avaliacoes.questoes.import.store');

        Route::post('/avaliacoes/{avaliacao}/questoes', [QuestaoController::class, 'store'])->name('avaliacoes.questoes.store');
        // Segmento literal ANTES do coringa {questao} — mesmo motivo da
        // lixeira em lote (ver routes/web.php mais abaixo).
        Route::delete('/avaliacoes/{avaliacao}/questoes/excluir-em-lote', [QuestaoController::class, 'destroyBulk'])->name('avaliacoes.questoes.destroyBulk');
        Route::delete('/avaliacoes/{avaliacao}/questoes/{questao}', [QuestaoController::class, 'destroy'])->name('avaliacoes.questoes.destroy');
        Route::post('/avaliacoes/{avaliacao}/questoes/{questao}/restaurar', [QuestaoController::class, 'restore'])->name('avaliacoes.questoes.restore');

        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/xlsx', [QuestaoExportController::class, 'xlsx'])->name('avaliacoes.questoes.export.xlsx');
        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/csv', [QuestaoExportController::class, 'csv'])->name('avaliacoes.questoes.export.csv');
        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/pdf', [QuestaoExportController::class, 'pdf'])->name('avaliacoes.questoes.export.pdf');

        Route::get('/avaliacoes/{avaliacao}/resultados/import', [ResultadoImportController::class, 'create'])
            ->name('avaliacoes.resultados.import');
        Route::post('/avaliacoes/{avaliacao}/resultados/import/preview', [ResultadoImportController::class, 'preview'])
            ->name('avaliacoes.resultados.import.preview');
        Route::post('/avaliacoes/{avaliacao}/resultados/import', [ResultadoImportController::class, 'store'])
            ->name('avaliacoes.resultados.import.store');

        Route::get('/avaliacoes/{avaliacao}/respondentes', [RespondenteController::class, 'index'])->name('avaliacoes.respondentes.index');
        Route::get('/avaliacoes/{avaliacao}/respondentes/show', [RespondenteController::class, 'show'])->name('avaliacoes.respondentes.show');
        Route::put('/avaliacoes/{avaliacao}/respondentes/respostas/{resposta}', [RespondenteController::class, 'updateResposta'])->name('avaliacoes.respondentes.respostas.update');
        Route::put('/avaliacoes/{avaliacao}/respondentes/vinculo', [RespondenteController::class, 'updateVinculo'])->name('avaliacoes.respondentes.vinculo.update');
        Route::delete('/avaliacoes/{avaliacao}/respondentes', [RespondenteController::class, 'destroyRespondente'])->name('avaliacoes.respondentes.destroy');
        Route::delete('/avaliacoes/{avaliacao}/periodos', [RespondenteController::class, 'destroyPeriodo'])->name('avaliacoes.periodos.destroy');
        Route::post('/avaliacoes/{avaliacao}/periodos/restaurar', [RespondenteController::class, 'restorePeriodo'])->name('avaliacoes.periodos.restore');

        Route::get('/avaliacoes/{avaliacao}/visualizacoes', [AvaliacaoVisualizacaoController::class, 'edit'])->name('avaliacoes.visualizacoes.edit');
        Route::put('/avaliacoes/{avaliacao}/visualizacoes', [AvaliacaoVisualizacaoController::class, 'update'])->name('avaliacoes.visualizacoes.update');

        Route::get('/lixeira', [LixeiraController::class, 'index'])->name('lixeira.index');
        // As rotas em lote (segmento literal) precisam vir ANTES das rotas
        // com {avaliacao}/{questao} (coringa) — senão o coringa "casa" com
        // "restaurar-em-lote"/"excluir-em-lote" primeiro, por ordem de
        // registro, e passa a string pro parâmetro tipado int do controller.
        Route::post('/lixeira/avaliacoes/restaurar-em-lote', [LixeiraController::class, 'restoreAvaliacoesBulk'])->name('lixeira.avaliacoes.restoreBulk');
        Route::delete('/lixeira/avaliacoes/excluir-em-lote', [LixeiraController::class, 'forceDeleteAvaliacoesBulk'])->name('lixeira.avaliacoes.forceDeleteBulk');
        Route::post('/lixeira/avaliacoes/{avaliacao}/restaurar', [LixeiraController::class, 'restoreAvaliacao'])->name('lixeira.avaliacoes.restore');
        Route::delete('/lixeira/avaliacoes/{avaliacao}', [LixeiraController::class, 'forceDeleteAvaliacao'])->name('lixeira.avaliacoes.forceDelete');
        Route::post('/lixeira/questoes/restaurar-em-lote', [LixeiraController::class, 'restoreQuestoesBulk'])->name('lixeira.questoes.restoreBulk');
        Route::delete('/lixeira/questoes/excluir-em-lote', [LixeiraController::class, 'forceDeleteQuestoesBulk'])->name('lixeira.questoes.forceDeleteBulk');
        Route::post('/lixeira/questoes/{questao}/restaurar', [LixeiraController::class, 'restoreQuestao'])->name('lixeira.questoes.restore');
        Route::delete('/lixeira/questoes/{questao}', [LixeiraController::class, 'forceDeleteQuestao'])->name('lixeira.questoes.forceDelete');

        Route::prefix('sistema')->name('sistema.')->group(function () {
            Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
            Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
            Route::get('/backups/{nome}/download', [BackupController::class, 'download'])->name('backups.download');

            Route::get('/atualizacao', [AtualizacaoController::class, 'index'])->name('atualizacao.index');
            Route::post('/atualizacao/verificar', [AtualizacaoController::class, 'verificar'])->name('atualizacao.verificar');
            Route::post('/atualizacao', [AtualizacaoController::class, 'store'])->name('atualizacao.store');

            Route::get('/atividades', [AtividadeController::class, 'index'])->name('atividades.index');

            Route::get('/legado', [LegadoController::class, 'index'])->name('legado.index');
            Route::post('/legado/banco', [LegadoController::class, 'importarDoBanco'])->name('legado.banco');
            Route::post('/legado/arquivo', [LegadoController::class, 'importarDeArquivo'])->name('legado.arquivo');
            Route::delete('/legado/tabelas', [LegadoController::class, 'excluirTabelas'])->name('legado.tabelas.destroy');

            Route::get('/configuracoes', [ConfiguracaoController::class, 'index'])->name('configuracoes.index');
            Route::post('/configuracoes', [ConfiguracaoController::class, 'update'])->name('configuracoes.update');

            Route::get('/portal', [PortalConfiguracaoController::class, 'index'])->name('portal.index');
            Route::put('/portal/aparencia', [PortalConfiguracaoController::class, 'atualizarAparencia'])->name('portal.aparencia');
            Route::put('/portal/captcha', [PortalConfiguracaoController::class, 'atualizarCaptcha'])->name('portal.captcha');
            Route::put('/portal/smtp', [PortalConfiguracaoController::class, 'atualizarSmtp'])->name('portal.smtp');
            Route::post('/portal/smtp/teste', [PortalConfiguracaoController::class, 'testarSmtp'])->name('portal.smtp.teste');
            Route::post('/portal/smtp/teste/verificar', [PortalConfiguracaoController::class, 'verificarTesteSmtp'])->name('portal.smtp.teste.verificar');
        });
        });
    });
});
