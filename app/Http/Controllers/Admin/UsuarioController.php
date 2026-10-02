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
 * Gestão de usuários do sistema: administradores (acesso total) e
 * coordenadores (acesso limitado aos cursos vinculados). Mesma tabela
 * (`admins`), diferenciados por `role` — duas abas na mesma tela.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $aba = $request->query('aba') === 'coordenadores' ? 'coordenadores' : 'administradores';

        $usuarios = ($aba === 'coordenadores' ? Admin::coordenadores() : Admin::administradores())
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
            'opcoesCurso' => Curso::nomesDisponiveis(),
        ]);
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        $coordenador = $request->validated('papel') === 'coordenador';

        $usuario = Admin::create([
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            // Coordenador pode não ter senha: `password_hash` é NOT NULL no schema legado, então vai um
            // hash de um valor aleatório que ninguém conhece — o login por senha dele simplesmente nunca passa.
            'password_hash' => Hash::make($request->validated('password') ?: Str::random(64)),
            'role' => $coordenador ? Admin::ROLE_COORDENADOR : Admin::ROLE_ADMIN,
        ]);

        if ($coordenador) {
            $usuario->sincronizarCursos($request->validated('cursos'));
        }

        AtividadeLogger::registrar($coordenador ? 'coordenador.criado' : 'administrador.criado', 'Admin', $usuario->id, array_filter([
            'username' => $usuario->username,
            'cursos' => $coordenador ? $request->validated('cursos') : null,
        ]));

        return redirect()
            ->route('usuarios.index', ['aba' => $coordenador ? 'coordenadores' : 'administradores'])
            ->with('status', ($coordenador ? 'Coordenador' : 'Administrador')." '{$usuario->username}' criado com sucesso.");
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

        AtividadeLogger::registrar($admin->ehCoordenador() ? 'coordenador.editado' : 'administrador.editado', 'Admin', $admin->id, array_filter([
            'username_antes' => $usernameAntes,
            'username_depois' => $admin->username,
            'senha_redefinida' => $senhaRedefinida,
            'cursos_antes' => $admin->ehCoordenador() ? $cursosAntes : null,
            'cursos_depois' => $admin->ehCoordenador() ? $request->validated('cursos') : null,
        ], fn ($v) => $v !== null));

        return redirect()
            ->route('usuarios.index', ['aba' => $admin->ehCoordenador() ? 'coordenadores' : 'administradores'])
            ->with('status', ($admin->ehCoordenador() ? 'Coordenador' : 'Administrador')." '{$admin->username}' atualizado com sucesso.");
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        if ($admin->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['admin' => 'Você não pode excluir a sua própria conta logada.']);
        }

        $username = $admin->username;
        $coordenador = $admin->ehCoordenador();
        $admin->delete();

        AtividadeLogger::registrar($coordenador ? 'coordenador.excluido' : 'administrador.excluido', 'Admin', $admin->id, ['username' => $username]);

        return redirect()
            ->route('usuarios.index', ['aba' => $coordenador ? 'coordenadores' : 'administradores'])
            ->with('status', ($coordenador ? 'Coordenador' : 'Administrador').' excluído com sucesso.');
    }
}
