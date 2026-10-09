<?php

namespace App\Services;

use App\Models\CronogramaCurso;
use App\Models\CronogramaItem;
use App\Models\CronogramaPendencia;
use App\Support\NomeCurso;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leituras do cronograma de atividades. O calendário e a lista de pendências do coordenador partem do MESMO recorte
 * (`$cursos`): só atividades que se aplicam a algum curso dele e só as pendências desses cursos. `$cursos = null` é a
 * visão do colaborador/administrador, que enxerga tudo. Volume pequeno (dezenas de atividades por semestre) — a
 * filtragem fina por grafia de curso acontece em PHP depois de uma consulta simples.
 */
class CronogramaService
{
    /**
     * O mês em semanas de domingo a sábado (inclui os dias dos meses vizinhos que completam a grade).
     *
     * @return array{mes: CarbonImmutable, semanas: array<int, array<int, CarbonImmutable>>, itens: Collection<string, Collection<int, CronogramaItem>>, total: int}
     */
    public function mes(CarbonImmutable $mes, ?array $cursos, array $filtros = []): array
    {
        $mes = $mes->startOfMonth();
        $inicio = $mes->startOfWeek(CarbonImmutable::SUNDAY);
        $fim = $mes->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

        $itens = $this->itens($cursos, $filtros)
            ->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()])
            ->get()
            ->filter(fn (CronogramaItem $i) => $cursos === null || $i->cursosDe($cursos)->isNotEmpty())
            ->groupBy(fn (CronogramaItem $i) => $i->data->toDateString());

        $semanas = [];
        for ($dia = $inicio, $n = 0; $dia->lte($fim); $dia = $dia->addDay(), $n++) {
            $semanas[intdiv($n, 7)][] = $dia;
        }

        return [
            'mes' => $mes,
            'semanas' => $semanas,
            'itens' => $itens,
            'total' => $itens->flatten(1)->filter(fn (CronogramaItem $i) => $i->data->isSameMonth($mes))->count(),
        ];
    }

    /**
     * As atividades do recorte em lista, da data mais antiga para a mais recente, com quantas pendências abertas cada uma tem
     * (só dos cursos do recorte). Aceita os mesmos filtros do calendário mais o intervalo `de`/`ate`.
     *
     * @param  ?array<int, string>  $cursos
     * @param  array<string, string>  $filtros  ver filtros()
     * @return Builder<CronogramaItem>
     */
    public function lista(?array $cursos, array $filtros = []): Builder
    {
        $escopo = ($filtros['curso'] ?? '') !== '' ? [$filtros['curso']] : $cursos;

        return $this->itens($cursos, $filtros)
            ->when(($filtros['de'] ?? '') !== '', fn ($q) => $q->whereDate('data', '>=', $filtros['de']))
            ->when(($filtros['ate'] ?? '') !== '', fn ($q) => $q->whereDate('data', '<=', $filtros['ate']))
            ->withCount(['pendencias as pendencias_abertas' => fn ($q) => $q->abertas()->when($escopo !== null, fn ($p) => $p->dosCursos($escopo))]);
    }

    /**
     * Os filtros da tela (calendário e lista) já saneados: texto livre, rotina e situação só se válidas, datas só no formato
     * AAAA-MM-DD, e o curso só se for um dos permitidos (qualquer outro vira "todos").
     *
     * @param  array<int, string>  $cursosValidos
     * @return array{busca: string, rotina: string, curso: string, status: string, de: string, ate: string}
     */
    public static function filtros(Request $request, array $cursosValidos): array
    {
        $texto = fn (string $nome) => trim((string) $request->query($nome, ''));
        $data = fn (string $nome) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto($nome)) === 1 ? $texto($nome) : '';
        $curso = $texto('curso');

        return [
            'busca' => mb_substr($texto('busca'), 0, 100),
            'rotina' => isset(CronogramaItem::ROTINAS[$texto('rotina')]) ? $texto('rotina') : '',
            'curso' => NomeCurso::estaEm($curso, $cursosValidos) ? $curso : '',
            'status' => isset(CronogramaItem::STATUS[$texto('status')]) ? $texto('status') : '',
            'de' => $data('de'),
            'ate' => $data('ate'),
        ];
    }

    /**
     * Cria ou atualiza a atividade e deixa os cursos dela exatamente como informado: curso novo entra como
     * "aguardando", curso que continua mantém a situação que já tinha, curso que saiu é removido.
     *
     * @param  array{data: string, rotina: string, projeto: string, descricao: string}  $dados
     * @param  array<int, string>  $cursos
     */
    public function salvar(?CronogramaItem $item, array $dados, array $cursos, ?int $adminId): CronogramaItem
    {
        return DB::transaction(function () use ($item, $dados, $cursos, $adminId) {
            $item ??= new CronogramaItem(['criado_por' => $adminId]);
            $item->fill($dados)->save();

            $existentes = $item->cursos()->get()->keyBy(fn (CronogramaCurso $c) => NomeCurso::chave($c->curso));
            $mantidos = [];

            foreach (NomeCurso::semRepetidos($cursos) as $curso) {
                $chave = NomeCurso::chave($curso);
                $mantidos[$chave] = true;
                if (! $existentes->has($chave)) {
                    $item->cursos()->create(['curso' => $curso, 'status' => CronogramaItem::AGUARDANDO]);
                }
            }

            $existentes->reject(fn ($c, $chave) => isset($mantidos[$chave]))->each->delete();

            return $item->unsetRelation('cursos');
        });
    }

    /**
     * Atualiza a situação de cada curso da atividade. `$status` é [id de cronograma_item_cursos => situação]; ids que
     * não são desta atividade e situações inválidas são ignorados. Devolve só o que de fato mudou, para a auditoria.
     *
     * @param  array<int|string, string>  $status
     * @return array<string, array{antes: string, depois: string}> curso => mudança
     */
    public function atualizarSituacao(CronogramaItem $item, array $status): array
    {
        $mudancas = [];

        foreach ($item->cursos()->get() as $curso) {
            $novo = $status[$curso->id] ?? null;
            if (! is_string($novo) || ! isset(CronogramaItem::STATUS[$novo]) || $novo === $curso->status) {
                continue;
            }
            $mudancas[$curso->curso] = ['antes' => $curso->status, 'depois' => $novo];
            $curso->update(['status' => $novo]);
        }

        return $mudancas;
    }

    /** Uma atividade para o coordenador: null quando nenhum curso dele está nela (a tela responde 404). */
    public function itemDoCoordenador(CronogramaItem $item, array $cursos): ?CronogramaItem
    {
        $item->load('cursos');

        return $item->cursosDe($cursos)->isEmpty() ? null : $item;
    }

    /**
     * Pendências do recorte, as abertas primeiro. `$cursos = null` = todas.
     *
     * @param  ?array<int, string>  $cursos
     * @return Builder<CronogramaPendencia>
     */
    public function pendencias(?array $cursos, string $status = '', string $curso = '', string $rotina = '')
    {
        return CronogramaPendencia::query()
            ->with(['item', 'registradoPor'])
            ->when($cursos !== null, fn ($q) => $q->dosCursos($cursos))
            ->when($curso !== '', fn ($q) => $q->whereIn('curso', NomeCurso::variantes([$curso])))
            ->when($status === 'abertas', fn ($q) => $q->abertas())
            ->when(isset(CronogramaPendencia::STATUS[$status]), fn ($q) => $q->where('status', $status))
            ->when(isset(CronogramaItem::ROTINAS[$rotina]), fn ($q) => $q->whereHas('item', fn ($i) => $i->where('rotina', $rotina)))
            // Abertas antes das resolvidas; dentro de cada grupo, o prazo mais próximo primeiro (sem prazo por último).
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [CronogramaItem::RESOLVIDO])
            ->orderByRaw('CASE WHEN prazo IS NULL THEN 1 ELSE 0 END')
            ->orderBy('prazo')
            ->orderByDesc('id');
    }

    /** Pendências abertas e as que já passaram do prazo, para os números do topo. */
    public function resumoPendencias(?array $cursos): array
    {
        $abertas = CronogramaPendencia::query()->abertas()
            ->when($cursos !== null, fn ($q) => $q->dosCursos($cursos))
            ->get(['id', 'curso', 'prazo']);

        if ($cursos !== null) {
            $abertas = $abertas->filter(fn ($p) => NomeCurso::estaEm($p->curso, $cursos));
        }

        return [
            'abertas' => $abertas->count(),
            'atrasadas' => $abertas->filter(fn ($p) => $p->prazo !== null && $p->prazo->isBefore(today()))->count(),
        ];
    }

    /**
     * Atividades do recorte com os filtros aplicados. Curso e situação valem para os cursos DO RECORTE: o coordenador que
     * filtra por "Pendente" só encontra atividades pendentes para um curso dele, nunca por causa de outro curso.
     *
     * @param  ?array<int, string>  $cursos
     * @param  array<string, string>  $filtros
     * @return Builder<CronogramaItem>
     */
    private function itens(?array $cursos, array $filtros): Builder
    {
        $escopo = ($filtros['curso'] ?? '') !== '' ? [$filtros['curso']] : $cursos;
        $status = $filtros['status'] ?? '';
        $busca = $filtros['busca'] ?? '';
        $rotina = $filtros['rotina'] ?? '';

        return CronogramaItem::query()
            ->with('cursos')
            ->when($escopo !== null || $status !== '', fn ($q) => $q->whereHas('cursos', fn ($c) => $c
                ->when($escopo !== null, fn ($c) => $c->whereIn('curso', NomeCurso::variantes($escopo)))
                ->when($status !== '', fn ($c) => $c->where('status', $status))))
            ->when(isset(CronogramaItem::ROTINAS[$rotina]), fn ($q) => $q->where('rotina', $rotina))
            ->when($busca !== '', function ($q) use ($busca) {
                $like = '%'.addcslashes($busca, '\%_').'%';

                return $q->where(fn ($w) => $w->where('projeto', 'like', $like)->orWhere('descricao', 'like', $like));
            })
            ->orderBy('data')->orderBy('rotina')->orderBy('id');
    }
}
