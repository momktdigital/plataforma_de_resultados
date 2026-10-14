<?php

namespace App\Http\Controllers\Sistema;

use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarConfiguracoesRequest;
use App\Models\ConfiguracaoSistema;
use App\Services\PlanoAcaoEmailService;
use App\Services\ReitorDashboardService;
use App\Models\Admin;
use App\Support\AtividadeLogger;
use App\Support\PlanoAcaoModulo;
use App\Support\RegraDeRisco;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConfiguracaoController extends Controller
{
    public function index(): View
    {
        return view('admin.sistema.configuracoes', [
            // Somente leitura: o repositório de onde o servidor baixa e executa código vem do .env.
            'atualizacaoRepositorio' => config('sistema.repositorio'),
            'backupManterUltimos' => ConfiguracaoSistema::valor('backup_manter_ultimos', '5'),
            'reitorMeta' => ReitorDashboardService::meta(),
            'regraDeRisco' => RegraDeRisco::atual(),
            // Plano de ação: o módulo liga/desliga (ou só para alguns usuários, em teste) e quem pode ser escolhido para o teste.
            'planoModo' => PlanoAcaoModulo::modo(),
            'planoUsuarios' => PlanoAcaoModulo::usuarios(),
            'planoCandidatos' => Admin::query()->orderBy('username')->get(['id', 'username', 'email', 'role'])
                ->filter(fn (Admin $a) => $a->papel() !== null)
                ->map(fn (Admin $a) => ['id' => (int) $a->id, 'nome' => $a->username, 'email' => $a->email, 'perfil' => match ($a->papel()) {
                    Admin::ROLE_ADMIN => 'Administrador', Admin::ROLE_COORDENADOR => 'Coordenador', Admin::ROLE_COLABORADOR => 'Colaborador', default => 'Reitor',
                }])->values()->all(),
            // Plano de ação: quem recebe o plano completo por e-mail quando um coordenador publica (um por linha).
            'planoEmails' => implode("\n", PlanoAcaoEmailService::destinatarios()),
            'planoEmailPronto' => app(PlanoAcaoEmailService::class)->configurado(),
        ]);
    }

    public function update(AtualizarConfiguracoesRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        ConfiguracaoSistema::definir('backup_manter_ultimos', (string) $dados['backup_manter_ultimos']);

        // Painel da reitoria: meta de participação (as telas leem na hora).
        if (isset($dados['reitor_meta_participacao'])) {
            ConfiguracaoSistema::definir('reitor_meta_participacao', (string) $dados['reitor_meta_participacao']);
        }

        // Plano de ação: ativo para todos, desativado, ou em teste só para os usuários escolhidos. Fica na auditoria.
        if ($request->boolean('plano_modulo_enviado')) {
            $antes = ['modo' => PlanoAcaoModulo::modo(), 'usuarios' => PlanoAcaoModulo::usuarios()];
            PlanoAcaoModulo::salvar($dados['plano_modo'], $dados['plano_usuarios'] ?? []);
            $depois = ['modo' => PlanoAcaoModulo::modo(), 'usuarios' => PlanoAcaoModulo::usuarios()];
            if ($depois !== $antes) {
                AtividadeLogger::registrar('configuracao.plano_modulo_alterado', 'ConfiguracaoSistema', null, ['antes' => $antes, 'depois' => $depois]);
            }
        }

        // Plano de ação: os e-mails que recebem o plano completo quando ele é publicado. Vazio = ninguém (só o colaborador acompanha no sistema).
        if ($request->boolean('plano_emails_enviado')) {
            $lista = PlanoAcaoEmailService::separar((string) ($dados['plano_emails'] ?? ''));
            $antes = PlanoAcaoEmailService::destinatarios();
            ConfiguracaoSistema::definir(PlanoAcaoEmailService::CHAVE, implode(',', $lista));
            if ($lista !== $antes) {
                AtividadeLogger::registrar('configuracao.plano_emails_alterado', 'ConfiguracaoSistema', null, ['antes' => $antes, 'depois' => $lista]);
            }
        }

        // Estudante em risco: vale para a lista do coordenador e para o painel da reitoria (cada avaliação pode sobrepor).
        if ($request->boolean('risco_enviado')) {
            $antes = RegraDeRisco::atual()->assinatura();
            $regra = new RegraDeRisco(
                $request->boolean('risco_acerto_ativo') ? (float) $dados['risco_acerto'] : null,
                $request->boolean('risco_faltas_ativo') ? (int) $dados['risco_faltas'] : null,
                ($dados['risco_operador'] ?? 'ou') === 'e' ? 'e' : 'ou',
            );
            RegraDeRisco::gravar($regra->acerto, $regra->faltas, $regra->operador);
            if ($regra->assinatura() !== $antes) {
                AtividadeLogger::registrar('configuracao.risco_alterado', 'ConfiguracaoSistema', null, ['antes' => $antes, 'depois' => $regra->assinatura()]);
            }
        }

        return redirect()->route('sistema.configuracoes.index')->with('status', 'Configurações salvas.');
    }
}
