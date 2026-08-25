<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdministradorRequest;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdministradorController extends Controller
{
    public function index(): View
    {
        $admins = Admin::orderBy('id')->get();

        return view('admin.administradores.index', ['admins' => $admins]);
    }

    public function store(StoreAdministradorRequest $request): RedirectResponse
    {
        Admin::create([
            'username' => $request->validated('username'),
            'password_hash' => Hash::make($request->validated('password')),
        ]);

        return redirect()
            ->route('administradores.index')
            ->with('status', "Administrador '{$request->validated('username')}' criado com sucesso.");
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        if ($admin->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['admin' => 'Você não pode excluir a sua própria conta logada.']);
        }

        $admin->delete();

        return redirect()->route('administradores.index')->with('status', 'Administrador excluído com sucesso.');
    }
}
