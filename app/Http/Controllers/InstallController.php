<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Support\EnvFileWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;
use PDOException;

/**
 * Wizard de instalação: requisitos → conexão com o banco → migrations →
 * primeiro administrador. Protegido pelo middleware `nao-instalado` — some
 * sozinho assim que existir um admin cadastrado.
 */
class InstallController extends Controller
{
    private const EXTENSOES_NECESSARIAS = ['pdo_mysql', 'mbstring', 'intl', 'json', 'openssl', 'zip'];

    public function inicio(): View|RedirectResponse
    {
        $this->garantirAppKey();

        return view('instalar.requisitos', [
            'phpOk' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'extensoes' => collect(self::EXTENSOES_NECESSARIAS)
                ->mapWithKeys(fn ($ext) => [$ext => extension_loaded($ext)]),
            'storageGravavel' => is_writable(storage_path()),
            'bootstrapCacheGravavel' => is_writable(base_path('bootstrap/cache')),
            'envGravavel' => is_writable(base_path('.env')),
        ]);
    }

    public function formularioBanco(): View
    {
        return view('instalar.banco');
    }

    public function testarEGravarBanco(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'porta' => ['required', 'integer'],
            'banco' => ['required', 'string', 'max:255'],
            'usuario' => ['required', 'string', 'max:255'],
            'senha' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            new PDO(
                "mysql:host={$dados['host']};port={$dados['porta']};dbname={$dados['banco']};charset=utf8mb4",
                $dados['usuario'],
                $dados['senha'] ?? '',
                [PDO::ATTR_TIMEOUT => 5],
            );
        } catch (PDOException $e) {
            return back()->withInput()->withErrors([
                'banco' => 'Não foi possível conectar: '.$e->getMessage(),
            ]);
        }

        (new EnvFileWriter(base_path('.env')))->atualizar([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dados['host'],
            'DB_PORT' => (string) $dados['porta'],
            'DB_DATABASE' => $dados['banco'],
            'DB_USERNAME' => $dados['usuario'],
            'DB_PASSWORD' => $dados['senha'] ?? '',
        ]);

        return redirect()->route('instalar.migrar');
    }

    /**
     * Etapa GET só mostra a confirmação — quem de fato roda as migrations é
     * migrar() (POST). Uma rota GET com efeito colateral (rodar migrate)
     * seria alcançável por um crawler/bot de preview de link, mesmo atrás do
     * middleware `nao-instalado`.
     */
    public function confirmarMigracao(): View
    {
        return view('instalar.confirmar-migracao');
    }

    public function migrar(): View
    {
        Artisan::call('migrate', ['--force' => true]);

        return view('instalar.migrar', ['saida' => Artisan::output()]);
    }

    public function formularioAdmin(): View|RedirectResponse
    {
        if (! Schema::hasTable('admins')) {
            return redirect()->route('instalar.banco')
                ->withErrors(['banco' => 'As tabelas ainda não foram criadas — refaça a etapa do banco.']);
        }

        return view('instalar.admin');
    }

    public function criarAdmin(Request $request): View
    {
        $dados = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:admins,username'],
            'password' => ['required', 'string', Password::min(10), 'confirmed'],
        ]);

        Admin::create([
            'username' => $dados['username'],
            'password_hash' => Hash::make($dados['password']),
        ]);

        return view('instalar.concluido');
    }

    private function garantirAppKey(): void
    {
        if (config('app.key')) {
            return;
        }

        $chave = 'base64:'.base64_encode(random_bytes(32));
        (new EnvFileWriter(base_path('.env')))->atualizar(['APP_KEY' => $chave]);
        config(['app.key' => $chave]);
    }
}
