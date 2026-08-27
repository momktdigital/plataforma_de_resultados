<?php

use App\Http\Controllers\Admin\AdministradorController;
use App\Http\Controllers\Admin\AlunoController;
use App\Http\Controllers\Admin\AvaliacaoController;
use App\Http\Controllers\Admin\AvaliacaoVisualizacaoController;
use App\Http\Controllers\Admin\BiController;
use App\Http\Controllers\Admin\CategoriaController;
use App\Http\Controllers\Admin\LixeiraController;
use App\Http\Controllers\Admin\MatriculaImportController;
use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\QuestaoController;
use App\Http\Controllers\Admin\QuestaoExportController;
use App\Http\Controllers\Admin\QuestaoImportController;
use App\Http\Controllers\Admin\RespondenteController;
use App\Http\Controllers\Admin\ResultadoImportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PortalController;
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
    Route::get('/migrar', [InstallController::class, 'migrar'])->name('migrar');
    Route::get('/admin', [InstallController::class, 'formularioAdmin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'criarAdmin'])->name('admin.criar');
});

Route::middleware('instalado')->group(function () {
    Route::get('/', function () {
        return redirect()->route(Auth::guard('admin')->check() ? 'avaliacoes.index' : 'portal.consulta');
    });

    Route::prefix('portal')->name('portal.')->middleware('throttle:30,1')->group(function () {
        Route::get('/', [PortalController::class, 'mostrarConsulta'])->name('consulta');
        Route::post('/consultar', [PortalController::class, 'consultar'])->name('consultar');
        Route::post('/verificar', [PortalController::class, 'verificar'])->name('verificar');
        Route::post('/reenviar', [PortalController::class, 'reenviar'])->name('reenviar');
        Route::get('/resultados', [PortalController::class, 'resultados'])->name('resultados');
        Route::get('/resultados/avaliacoes/{avaliacao}', [PortalController::class, 'resultadoAvaliacao'])->name('resultados.avaliacao');
        Route::get('/sair', [PortalController::class, 'sair'])->name('sair');
    });

    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.attempt');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/alunos', [AlunoController::class, 'index'])->name('alunos.index');
        Route::get('/alunos/importar', [MatriculaImportController::class, 'create'])->name('alunos.importar');
        Route::post('/alunos/importar', [MatriculaImportController::class, 'store'])->name('alunos.importar.store');
        Route::get('/alunos/novo', [AlunoController::class, 'create'])->name('alunos.create');
        Route::post('/alunos', [AlunoController::class, 'store'])->name('alunos.store');
        Route::get('/alunos/{aluno}/editar', [AlunoController::class, 'edit'])->name('alunos.edit');
        Route::put('/alunos/{aluno}', [AlunoController::class, 'update'])->name('alunos.update');
        Route::delete('/alunos/{aluno}', [AlunoController::class, 'destroy'])->name('alunos.destroy');

        Route::get('/administradores', [AdministradorController::class, 'index'])->name('administradores.index');
        Route::post('/administradores', [AdministradorController::class, 'store'])->name('administradores.store');
        Route::delete('/administradores/{admin}', [AdministradorController::class, 'destroy'])->name('administradores.destroy');

        Route::get('/perfil', [PerfilController::class, 'edit'])->name('perfil.edit');
        Route::put('/perfil/senha', [PerfilController::class, 'updateSenha'])->name('perfil.senha');

        Route::get('/categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');

        Route::get('/avaliacoes', [AvaliacaoController::class, 'index'])->name('avaliacoes.index');
        Route::post('/avaliacoes', [AvaliacaoController::class, 'store'])->name('avaliacoes.store');
        Route::get('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'show'])->name('avaliacoes.show');
        Route::put('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'update'])->name('avaliacoes.update');
        Route::delete('/avaliacoes/{avaliacao}', [AvaliacaoController::class, 'destroy'])->name('avaliacoes.destroy');

        Route::get('/avaliacoes/{avaliacao}/questoes/import', [QuestaoImportController::class, 'create'])
            ->name('avaliacoes.questoes.import');
        Route::post('/avaliacoes/{avaliacao}/questoes/import', [QuestaoImportController::class, 'store'])
            ->name('avaliacoes.questoes.import.store');

        Route::post('/avaliacoes/{avaliacao}/questoes', [QuestaoController::class, 'store'])->name('avaliacoes.questoes.store');
        Route::delete('/avaliacoes/{avaliacao}/questoes/{questao}', [QuestaoController::class, 'destroy'])->name('avaliacoes.questoes.destroy');
        Route::post('/avaliacoes/{avaliacao}/questoes/{questao}/restaurar', [QuestaoController::class, 'restore'])->name('avaliacoes.questoes.restore');

        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/xlsx', [QuestaoExportController::class, 'xlsx'])->name('avaliacoes.questoes.export.xlsx');
        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/csv', [QuestaoExportController::class, 'csv'])->name('avaliacoes.questoes.export.csv');
        Route::get('/avaliacoes/{avaliacao}/questoes/exportar/pdf', [QuestaoExportController::class, 'pdf'])->name('avaliacoes.questoes.export.pdf');

        Route::get('/avaliacoes/{avaliacao}/resultados/import', [ResultadoImportController::class, 'create'])
            ->name('avaliacoes.resultados.import');
        Route::post('/avaliacoes/{avaliacao}/resultados/import', [ResultadoImportController::class, 'store'])
            ->name('avaliacoes.resultados.import.store');

        Route::get('/avaliacoes/{avaliacao}/respondentes', [RespondenteController::class, 'index'])->name('avaliacoes.respondentes.index');
        Route::get('/avaliacoes/{avaliacao}/respondentes/show', [RespondenteController::class, 'show'])->name('avaliacoes.respondentes.show');
        Route::delete('/avaliacoes/{avaliacao}/periodos', [RespondenteController::class, 'destroyPeriodo'])->name('avaliacoes.periodos.destroy');
        Route::post('/avaliacoes/{avaliacao}/periodos/restaurar', [RespondenteController::class, 'restorePeriodo'])->name('avaliacoes.periodos.restore');

        Route::get('/avaliacoes/{avaliacao}/bi', [BiController::class, 'index'])->name('avaliacoes.bi');

        Route::get('/avaliacoes/{avaliacao}/visualizacoes', [AvaliacaoVisualizacaoController::class, 'edit'])->name('avaliacoes.visualizacoes.edit');
        Route::put('/avaliacoes/{avaliacao}/visualizacoes', [AvaliacaoVisualizacaoController::class, 'update'])->name('avaliacoes.visualizacoes.update');

        Route::get('/lixeira', [LixeiraController::class, 'index'])->name('lixeira.index');
        Route::post('/lixeira/avaliacoes/{avaliacao}/restaurar', [LixeiraController::class, 'restoreAvaliacao'])->name('lixeira.avaliacoes.restore');
        Route::delete('/lixeira/avaliacoes/{avaliacao}', [LixeiraController::class, 'forceDeleteAvaliacao'])->name('lixeira.avaliacoes.forceDelete');
        Route::post('/lixeira/questoes/{questao}/restaurar', [LixeiraController::class, 'restoreQuestao'])->name('lixeira.questoes.restore');
        Route::delete('/lixeira/questoes/{questao}', [LixeiraController::class, 'forceDeleteQuestao'])->name('lixeira.questoes.forceDelete');

        Route::prefix('sistema')->name('sistema.')->group(function () {
            Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
            Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
            Route::get('/backups/{nome}/download', [BackupController::class, 'download'])->name('backups.download');

            Route::get('/atualizacao', [AtualizacaoController::class, 'index'])->name('atualizacao.index');
            Route::post('/atualizacao', [AtualizacaoController::class, 'store'])->name('atualizacao.store');

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
