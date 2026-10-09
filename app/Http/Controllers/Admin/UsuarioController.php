<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsuarioRequest;
use App\Http\Requests\UpdateUsuarioRequest;
use App\Models\Admin;
use App\Models\Curso;
use App\Support\AtividadeLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Gestão de usuários do sistema: administradores (acesso total),
 * coordenadores (acesso limitado aos cursos vinculados) e reitores (só
 * leitura, indicadores agregados de todos os cursos) e colaboradores (montam o cronograma de atividades). Mesma tabela
 * (`admins`), diferenciados por `role` — uma aba por perfil na mesma tela.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $aba = in_array($request->query('aba'), ['coordenadores', 'reitores', 'colaboradores'], true) ? $request->query('aba') : 'administradores';

        $usuarios = match ($aba) {
            'coordenadores' => Admin::coordenadores(),
            'reitores' => Admin::reitores(),
            'colaboradores' => Admin::colaboradores(),
            default => Admin::administradores(),
        };
        $usuarios = $usuarios
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        // Cursos de cada coordenador da página, numa consulta só (sem N+1).
        $cursosPorCoordenador = $aba === 'coordenadores'
            ? DB::table('admin_cursos')->whereIn('admin_id', $usuarios->pluck('id'))->orderBy('curso')->get()->groupBy('admin_id')->map->pluck('curso')
            : collect();

        return view('admin.usuarios.index', [
            'aba' => $aba,
            'usuarios' => $usuarios,
            'cursosPorCoordenador' => $cursosPorCoordenador,
            'totalAdministradores' => Admin::administradores()->count(),
            'totalCoordenadores' => Admin::coordenadores()->count(),
            'totalReitores' => Admin::reitores()->count(),
            'totalColaboradores' => Admin::colaboradores()->count(),
            'opcoesCurso' => Curso::nomesDisponiveis(),
        ]);
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $coordenador = $request->validated('papel') === 'coordenador';
        $reitor = $request->validated('papel') === 'reitor';
        $colaborador = $request->validated('papel') === 'colaborador';

        $usuario = Admin::create([
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            // Coordenador, reitor e colaborador podem não ter senha: `password_hash` é NOT NULL no schema legado, então vai um
            // hash de um valor aleatório que ninguém conhece — o login por senha dele simplesmente nunca passa.
            'password_hash' => Hash::make($request->validated('password') ?: Str::random(64)),
            'role' => match (true) {
                $coordenador => Admin::ROLE_COORDENADOR,
                $reitor => Admin::ROLE_REITOR,
                $colaborador => Admin::ROLE_COLABORADOR,
                default => Admin::ROLE_ADMIN,
            },
        ]);

        if ($coordenador) {
            $usuario->sincronizarCursos($request->validated('cursos'));
        }

        AtividadeLogger::registrar($colaborador ? 'colaborador.criado' : ($reitor ? 'reitor.criado' : ($coordenador ? 'coordenador.criado' : 'administrador.criado')), 'Admin', $usuario->id, array_filter([
            'username' => $usuario->username,
            'cursos' => $coordenador ? $request->validated('cursos') : null,
        ]));

        return redirect()
            ->route('usuarios.index', ['aba' => $this->abaDo($usuario)])
            ->with('status', $this->rotuloDoPerfil($usuario)." '{$usuario->username}' criado com sucesso.");
    }

    public function edit(Admin $admin): View
    {
        return view('admin.usuarios.edit', [
            'admin' => $admin,
            'opcoesCurso' => Curso::nomesDisponiveis(),
            'cursosSelecionados' => old('cursos', $admin->cursos()),
        ]);
    }

    public function update(UpdateUsuarioRequest $request, Admin $admin): RedirectResponse
    {
        $usernameAntes = $admin->username;
        $cursosAntes = $admin->cursos();

        $admin->username = $request->validated('username');
        $admin->email = $request->validated('email');

        $senhaRedefinida = ! empty($request->validated('password'));
        if ($senhaRedefinida) {
            $admin->password_hash = Hash::make($request->validated('password'));
        }

        $admin->save();

        if ($admin->ehCoordenador()) {
            $admin->sincronizarCursos($request->validated('cursos'));
        }

        AtividadeLogger::registrar($admin->ehColaborador() ? 'colaborador.editado' : ($admin->ehReitor() ? 'reitor.editado' : ($admin->ehCoordenador() ? 'coordenador.editado' : 'administrador.editado')), 'Admin', $admin->id, array_filter([
            'username_antes' => $usernameAntes,
            'username_depois' => $admin->username,
            'senha_redefinida' => $senhaRedefinida,
            'cursos_antes' => $admin->ehCoordenador() ? $cursosAntes : null,
            'cursos_depois' => $admin->ehCoordenador() ? $request->validated('cursos') : null,
        ], fn ($v) => $v !== null));

        return redirect()
            ->route('usuarios.index', ['aba' => $this->abaDo($admin)])
            ->with('status', $this->rotuloDoPerfil($admin)." '{$admin->username}' atualizado com sucesso.");
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        if ($admin->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['admin' => 'Você não pode excluir a sua própria conta logada.']);
        }

        $username = $admin->username;
        $aba = $this->abaDo($admin);
        $rotulo = $this->rotuloDoPerfil($admin);
        $acao = $admin->ehColaborador() ? 'colaborador.excluido' : ($admin->ehReitor() ? 'reitor.excluido' : ($admin->ehCoordenador() ? 'coordenador.excluido' : 'administrador.excluido'));
        $admin->delete();

        AtividadeLogger::registrar($acao, 'Admin', $admin->id, ['username' => $username]);

        return redirect()
            ->route('usuarios.index', ['aba' => $aba])
            ->with('status', $rotulo.' excluído com sucesso.');
    }

    private function abaDo(Admin $usuario): string
    {
        return match (true) {
            $usuario->ehColaborador() => 'colaboradores',
            $usuario->ehReitor() => 'reitores',
            $usuario->ehCoordenador() => 'coordenadores',
            default => 'administradores',
        };
    }

    private function rotuloDoPerfil(Admin $usuario): string
    {
        return match (true) {
            $usuario->ehColaborador() => 'Colaborador',
            $usuario->ehReitor() => 'Reitor',
            $usuario->ehCoordenador() => 'Coordenador',
            default => 'Administrador',
        };
    }
}
