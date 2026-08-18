<?php

use App\Http\Controllers\Admin\AdministradorController;
use App\Http\Controllers\Admin\AlunoController;
use App\Http\Controllers\Admin\BiController;
use App\Http\Controllers\Admin\LixeiraController;
use App\Http\Controllers\Admin\MatriculaImportController;
use App\Http\Controllers\Admin\PerfilController;
use App\Http\Controllers\Admin\ProvaController;
use App\Http\Controllers\Admin\QuestaoController;
use App\Http\Controllers\Admin\QuestaoImportController;
use App\Http\Controllers\Admin\RespondenteController;
use App\Http\Controllers\Admin\ResultadoImportController;
use App\Http\Controllers\AssetLegadoController;
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
        return redirect()->route(Auth::guard('admin')->check() ? 'provas.index' : 'login');
    });

    Route::get('/assets/img/{arquivo}', [AssetLegadoController::class, 'logo'])
        ->where('arquivo', '[^/]+')
        ->name('assets.logo');

    Route::prefix('portal')->name('portal.')->middleware('throttle:30,1')->group(function () {
        Route::get('/', [PortalController::class, 'mostrarConsulta'])->name('consulta');
        Route::post('/consultar', [PortalController::class, 'consultar'])->name('consultar');
        Route::post('/verificar', [PortalController::class, 'verificar'])->name('verificar');
        Route::post('/reenviar', [PortalController::class, 'reenviar'])->name('reenviar');
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

        Route::get('/provas', [ProvaController::class, 'index'])->name('provas.index');
        Route::post('/provas', [ProvaController::class, 'store'])->name('provas.store');
        Route::get('/provas/{prova}', [ProvaController::class, 'show'])->name('provas.show');
        Route::put('/provas/{prova}', [ProvaController::class, 'update'])->name('provas.update');
        Route::delete('/provas/{prova}', [ProvaController::class, 'destroy'])->name('provas.destroy');

        Route::get('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'create'])
            ->name('provas.questoes.import');
        Route::post('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'store'])
            ->name('provas.questoes.import.store');

        Route::post('/provas/{prova}/questoes', [QuestaoController::class, 'store'])->name('provas.questoes.store');
        Route::delete('/provas/{prova}/questoes/{questao}', [QuestaoController::class, 'destroy'])->name('provas.questoes.destroy');
        Route::post('/provas/{prova}/questoes/{questao}/restaurar', [QuestaoController::class, 'restore'])->name('provas.questoes.restore');

        Route::get('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'create'])
            ->name('provas.resultados.import');
        Route::post('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'store'])
            ->name('provas.resultados.import.store');

        Route::get('/provas/{prova}/respondentes', [RespondenteController::class, 'index'])->name('provas.respondentes.index');
        Route::get('/provas/{prova}/respondentes/show', [RespondenteController::class, 'show'])->name('provas.respondentes.show');
        Route::delete('/provas/{prova}/periodos', [RespondenteController::class, 'destroyPeriodo'])->name('provas.periodos.destroy');
        Route::post('/provas/{prova}/periodos/restaurar', [RespondenteController::class, 'restorePeriodo'])->name('provas.periodos.restore');

        Route::get('/provas/{prova}/bi', [BiController::class, 'index'])->name('provas.bi');

        Route::get('/lixeira', [LixeiraController::class, 'index'])->name('lixeira.index');
        Route::post('/lixeira/provas/{prova}/restaurar', [LixeiraController::class, 'restoreProva'])->name('lixeira.provas.restore');
        Route::delete('/lixeira/provas/{prova}', [LixeiraController::class, 'forceDeleteProva'])->name('lixeira.provas.forceDelete');
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
