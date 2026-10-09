<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlanoAcao;
use App\Models\PlanoAcaoAcao;
use App\Models\PlanoAcaoAnexo;
use App\Services\PlanoAcaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O plano já aprovado, em execução: o coordenador atualiza as ações (situação, prazo, notas de andamento), comenta,
 * encerra ou cancela o plano. Tudo vira evento no histórico do plano (ver PlanoAcaoService).
 */
class CoordenadorPlanoExecucaoController extends PlanoAcaoPainelController
{
    public function __construct(private readonly PlanoAcaoService $servico) {}

    public function atualizarAcao(Request $request, PlanoAcao $plano, PlanoAcaoAcao $acao): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);
        abort_unless($acao->plano_id === $plano->id, 404);

        $dados = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(PlanoAcaoAcao::STATUS))],
            'prazo' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'nota' => ['nullable', 'string', 'max:3000'],
            ...self::regrasDeAnexo(),
        ], [
            'status.in' => 'Situação inválida.',
            'prazo.date_format' => 'Informe o novo prazo como uma data válida.',
            'nota.max' => 'A nota pode ter no máximo 3000 caracteres.',
            ...self::mensagensDeAnexo(),
        ]);

        try {
            $this->servico->atualizarAcao($plano, $acao, $usuario, $dados);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->withFragment('acao-'.$acao->id)->with('status', 'Ação atualizada.');
    }

    public function comentar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);
        abort_if($plano->status === PlanoAcao::RASCUNHO, 404);

        $dados = $request->validate(['texto' => ['required', 'string', 'min:3', 'max:3000']], [
            'texto.required' => 'Escreva o comentário.',
            'texto.min' => 'O comentário é curto demais.',
            'texto.max' => 'O comentário pode ter no máximo 3000 caracteres.',
        ]);

        $this->servico->comentar($plano, $usuario, $dados['texto']);

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Comentário registrado.');
    }

    public function encerrar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $anexos = $request->validate(self::regrasDeAnexo(), self::mensagensDeAnexo());
            $this->servico->encerrar($plano, $usuario, (string) $request->input('conclusao', ''), $anexos);
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Plano encerrado.');
    }

    /** Baixa um arquivo anexado (ou segue o link) — só para quem enxerga o plano (coordenador do curso, inclusive o reitor na visão do curso). */
    public function anexo(PlanoAcao $plano, PlanoAcaoAnexo $anexo): StreamedResponse|RedirectResponse
    {
        $this->doCoordenador($plano);

        return self::entregarAnexo($plano, $anexo);
    }

    /** @return array<string, array<int, mixed>> */
    public static function regrasDeAnexo(): array
    {
        return [
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'link_titulo' => ['nullable', 'string', 'max:190'],
            'arquivo' => ['nullable', 'file', 'max:'.PlanoAcaoAnexo::MAXIMO_KB, 'mimes:'.implode(',', PlanoAcaoAnexo::EXTENSOES)],
        ];
    }

    /** @return array<string, string> */
    public static function mensagensDeAnexo(): array
    {
        return [
            'link_url.url' => 'Informe o link da evidência começando por http:// ou https://.',
            'link_url.max' => 'O link pode ter no máximo 500 caracteres.',
            'arquivo.max' => 'O arquivo pode ter no máximo '.(PlanoAcaoAnexo::MAXIMO_KB / 1024).' MB.',
            'arquivo.mimes' => 'Envie um arquivo '.implode(', ', PlanoAcaoAnexo::EXTENSOES).'.',
            'arquivo.uploaded' => 'Não foi possível enviar o arquivo (ele pode ser maior que o limite do servidor).',
        ];
    }

    /** O arquivo sai do disco privado com nome original, como download; o link vai para o endereço informado. */
    public static function entregarAnexo(PlanoAcao $plano, PlanoAcaoAnexo $anexo): StreamedResponse|RedirectResponse
    {
        abort_unless($anexo->plano_id === $plano->id, 404);

        if (! $anexo->ehArquivo()) {
            return redirect()->away((string) $anexo->url);
        }

        abort_unless($anexo->caminho !== null && Storage::disk(PlanoAcaoAnexo::DISCO)->exists($anexo->caminho), 404);

        return Storage::disk(PlanoAcaoAnexo::DISCO)->download($anexo->caminho, $anexo->nome_original ?: 'evidencia');
    }

    public function cancelar(Request $request, PlanoAcao $plano): RedirectResponse
    {
        $usuario = $this->coordenadorQueEscreve();
        $this->doCoordenador($plano);

        try {
            $this->servico->cancelar($plano, $usuario, (string) $request->input('motivo', ''));
        } catch (\DomainException $e) {
            return redirect()->route('coordenador.planos.show', $plano)->with('erro', $e->getMessage());
        }

        return redirect()->route('coordenador.planos.show', $plano)->with('status', 'Plano cancelado.');
    }
}
