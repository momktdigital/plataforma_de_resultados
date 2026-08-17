<?php

use App\Http\Controllers\Admin\AlunoController;
use App\Http\Controllers\Admin\MatriculaImportController;
use App\Http\Controllers\Admin\ProvaController;
use App\Http\Controllers\Admin\QuestaoImportController;
use App\Http\Controllers\Admin\ResultadoImportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\Sistema\AtualizacaoController;
use App\Http\Controllers\Sistema\BackupController;
use App\Http\Controllers\Sistema\ConfiguracaoController;
use App\Http\Controllers\Sistema\LegadoController;
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

        Route::get('/provas', [ProvaController::class, 'index'])->name('provas.index');
        Route::post('/provas', [ProvaController::class, 'store'])->name('provas.store');
        Route::get('/provas/{prova}', [ProvaController::class, 'show'])->name('provas.show');

        Route::get('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'create'])
            ->name('provas.questoes.import');
        Route::post('/provas/{prova}/questoes/import', [QuestaoImportController::class, 'store'])
            ->name('provas.questoes.import.store');

        Route::get('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'create'])
            ->name('provas.resultados.import');
        Route::post('/provas/{prova}/resultados/import', [ResultadoImportController::class, 'store'])
            ->name('provas.resultados.import.store');

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
        });
    });
});
