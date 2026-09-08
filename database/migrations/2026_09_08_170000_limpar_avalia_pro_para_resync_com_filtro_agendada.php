<?php

use App\Models\ConfiguracaoSistema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Apaga todo o dado de aluno já sincronizado do Avalia Pro (respostas,
// métricas, questões, resumos) e reseta o watermark — força a próxima
// sincronização a reconstruir tudo do zero, já com o filtro novo de
// RedshiftAvaliaExtractor (ponto 5 da docblock da classe).
//
// Motivo: até agora, um aluno com prova AGENDADA (que ainda nem aconteceu)
// era importado com nota/resposta em branco e aparecia como "ausente" no
// nosso sistema — confirmado com dado real (aluna com prova agendada pra
// mais de uma semana no futuro, já sincronizada como ausente). O filtro
// novo impede que isso aconteça de novo, mas não corrige sozinho o que JÁ
// foi importado errado: como essas respostas já estão gravadas (todas em
// branco), uma sincronização incremental normal não as reprocessa — e não
// dá pra distinguir localmente, só olhando o que já está no nosso banco,
// quem "ainda não fez" de quem é "ausente de verdade" (os dois ficam
// idênticos: tudo em branco). A única forma segura de corrigir é apagar e
// deixar a sincronização completa reconstruir do zero, já com o filtro certo.
//
// Só mexe em dado de origem avalia_pro — nunca em avaliação/resposta
// importada manualmente. Não apaga a linha de `avaliacoes` em si (mantém o
// código estável, evita quebrar link/histórico) — só o que é recalculado a
// partir dela.
return new class extends Migration
{
    public function up(): void
    {
        $codigos = DB::table('avaliacoes')->where('origem', 'avalia_pro')->pluck('codigo');

        if ($codigos->isNotEmpty()) {
            DB::table('respostas')->whereIn('avaliacao_codigo', $codigos)->delete();
            DB::table('resultado_metricas')->whereIn('avaliacao_codigo', $codigos)->delete();
            DB::table('questoes')->whereIn('avaliacao_codigo', $codigos)->delete();
            DB::table('resultado_resumos')->whereIn('avaliacao_codigo', $codigos)->delete();
        }

        ConfiguracaoSistema::definir('avalia_watermark_notas_avalia_pro', null);
        ConfiguracaoSistema::definir('avalia_watermark_respostas_avalia_pro', null);
    }

    public function down(): void
    {
        // Nada a desfazer — os dados apagados são derivados (recriados por
        // uma nova sincronização); não há como restaurar o estado anterior.
    }
};
