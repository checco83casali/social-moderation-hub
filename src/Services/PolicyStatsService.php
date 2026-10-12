<?php
// src/Services/PolicyStatsService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Utilizzo ed efficacia di ogni versione del prompt (policy), ricavati dal registro di moderazione.
 *
 * Una "valutazione" è una riga del registro con un verdetto emesso da un modello Claude
 * (le risposte in cache e le righe di sistema sono escluse).
 *
 * Errore accertato = lo stato finale del commento contraddice l'ultima decisione autonoma dell'AI,
 * cioè un moderatore l'ha ribaltata:
 *   - troppo permissivo: l'AI aveva approvato, il commento è stato nascosto
 *   - troppo severo:     l'AI aveva nascosto (o segnalato), il commento è stato ripristinato (anche via ricorso)
 * Si guarda solo l'ultima valutazione AI di ogni commento: le precedenti sono state sostituite.
 * Le valutazioni "incerto" non sono errori: sono escalation, con l'esito deciso dall'umano.
 * Efficacia = 1 − errori / decisioni autonome. Le decisioni mai contestate contano come corrette.
 */
final class PolicyStatsService
{
    public const SCOPES = ['all', 'prod', 'dev'];

    private const HIDDEN_STATUSES  = ['hidden', 'hidden_reportable', 'removed', 'reported_legal', 'escalated_reportable'];
    private const VISIBLE_STATUSES = ['approved'];

    /** Fasce di confidenza per tarare le soglie: [etichetta, minimo incluso, massimo escluso]. */
    private const BANDS = [['< 60%', 0.0, 0.60], ['60–79%', 0.60, 0.80], ['80–89%', 0.80, 0.90], ['≥ 90%', 0.90, 1.01]];

    /**
     * @param 'all'|'prod'|'dev' $scope
     * @return array{scope:string, policies:list<array<string,mixed>>, totals:array<string,mixed>}
     */
    public function compute(string $scope = 'all'): array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';

        $query = DB::table('moderation_log as ml')
            ->join('comments as c', 'c.id', '=', 'ml.comment_id')
            ->where('ml.ai_model', 'like', 'claude%')
            ->whereNotNull('ml.ai_decision')
            ->select(['ml.policy_id', 'ml.comment_id', 'ml.ai_decision', 'ml.ai_confidence', 'ml.created_at', 'c.status', 'c.is_dev'])
            ->orderBy('ml.id');
        if ($scope === 'prod') $query->where('c.is_dev', 0);
        if ($scope === 'dev')  $query->where('c.is_dev', 1);

        /** @var array<int, array<string,mixed>> $acc */
        $acc = [];
        /** @var array<int, object> $latest ultima valutazione AI di ogni commento: è l'unica che si confronta con l'esito */
        $latest = [];
        foreach ($query->cursor() as $row) {
            $pid = (int) $row->policy_id;
            if (!isset($acc[$pid])) {
                $acc[$pid] = $this->blank();
            }
            $a = &$acc[$pid];
            $a['uses']++;
            $a['comments'][(int) $row->comment_id] = true;
            $a[((int) $row->is_dev) === 1 ? 'dev_uses' : 'prod_uses']++;
            $a['first_used'] = $a['first_used'] === null || $row->created_at < $a['first_used'] ? $row->created_at : $a['first_used'];
            $a['last_used']  = $a['last_used']  === null || $row->created_at > $a['last_used']  ? $row->created_at : $a['last_used'];
            unset($a);
            $latest[(int) $row->comment_id] = $row;
        }

        // Efficacia: l'ultima valutazione AI di ogni commento contro il suo stato finale.
        // Se l'esito contraddice l'AI è stato un umano a cambiarlo (nascondere, ripristinare, accogliere
        // un ricorso): vale anche in modalità sviluppo, dove le decisioni non lasciano righe "umane".
        foreach ($latest as $row) {
            $a = &$acc[(int) $row->policy_id];
            $decision = (string) $row->ai_decision;
            $conf     = $row->ai_confidence !== null ? (float) $row->ai_confidence : null;
            $status   = (string) $row->status;
            $hidden   = in_array($status, self::HIDDEN_STATUSES, true);
            $visible  = in_array($status, self::VISIBLE_STATUSES, true);

            if ($conf !== null) { $a['conf_sum'] += $conf; $a['conf_n']++; }

            if ($decision === 'uncertain') {
                $a['by_decision']['uncertain']++;
                $a['escalated']++;
                if ($hidden)       $a['escalated_hidden']++;
                elseif ($visible)  $a['escalated_visible']++;
                else               $a['escalated_pending']++;
                unset($a);
                continue;
            }

            $isAllow = $decision === 'allow';
            $a['by_decision'][$isAllow ? 'allow' : ($decision === 'reportable' ? 'reportable' : 'hide')]++;
            $a['decisive']++;

            $permissive = $isAllow && $hidden;
            $severe     = !$isAllow && $visible;
            if ($permissive) $a['too_permissive']++;
            if ($severe)     $a['too_strict']++;
            $isError = $permissive || $severe;

            if ($conf !== null) {
                $key = $isError ? 'err' : 'ok';
                $a["conf_{$key}_sum"] += $conf;
                $a["conf_{$key}_n"]++;
                foreach (self::BANDS as $i => [, $lo, $hi]) {
                    if ($conf >= $lo && $conf < $hi) {
                        $a['bands'][$i]['n']++;
                        if ($isError) $a['bands'][$i]['errors']++;
                        if ($permissive) $a['bands'][$i]['too_permissive']++;
                        if ($severe)     $a['bands'][$i]['too_strict']++;
                        break;
                    }
                }
            }
            unset($a);
        }

        $policies = [];
        $totals = $this->blank();
        foreach (DB::table('policies')->orderByDesc('id')->get(['id', 'name', 'version', 'is_active', 'created_at']) as $p) {
            $a = $acc[(int) $p->id] ?? $this->blank();
            $policies[] = ['id' => (int) $p->id, 'name' => $p->name, 'version' => (int) $p->version,
                           'is_active' => (bool) $p->is_active, 'created_at' => $p->created_at] + $this->summarise($a);
        }
        foreach ($acc as $a) { $totals = $this->merge($totals, $a); }

        return ['scope' => $scope, 'policies' => $policies, 'totals' => $this->summarise($totals)];
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'uses' => 0, 'comments' => [], 'prod_uses' => 0, 'dev_uses' => 0, 'first_used' => null, 'last_used' => null,
            'by_decision' => ['allow' => 0, 'hide' => 0, 'reportable' => 0, 'uncertain' => 0],
            'decisive' => 0, 'too_permissive' => 0, 'too_strict' => 0,
            'escalated' => 0, 'escalated_hidden' => 0, 'escalated_visible' => 0, 'escalated_pending' => 0,
            'conf_sum' => 0.0, 'conf_n' => 0, 'conf_ok_sum' => 0.0, 'conf_ok_n' => 0, 'conf_err_sum' => 0.0, 'conf_err_n' => 0,
            'bands' => array_map(static fn($b) => ['label' => $b[0], 'n' => 0, 'errors' => 0, 'too_permissive' => 0, 'too_strict' => 0], self::BANDS),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function merge(array $a, array $b): array
    {
        foreach (['uses', 'prod_uses', 'dev_uses', 'decisive', 'too_permissive', 'too_strict', 'escalated', 'escalated_hidden',
                  'escalated_visible', 'escalated_pending', 'conf_sum', 'conf_n', 'conf_ok_sum', 'conf_ok_n', 'conf_err_sum', 'conf_err_n'] as $k) {
            $a[$k] += $b[$k];
        }
        foreach ($b['by_decision'] as $k => $v) { $a['by_decision'][$k] += $v; }
        foreach ($b['bands'] as $i => $band) {
            foreach (['n', 'errors', 'too_permissive', 'too_strict'] as $k) { $a['bands'][$i][$k] += $band[$k]; }
        }
        $a['comments'] = $a['comments'] + $b['comments'];
        foreach (['first_used' => 'min', 'last_used' => 'max'] as $k => $fn) {
            if ($b[$k] !== null) { $a[$k] = $a[$k] === null ? $b[$k] : ($fn === 'min' ? min($a[$k], $b[$k]) : max($a[$k], $b[$k])); }
        }
        return $a;
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function summarise(array $a): array
    {
        $errors = $a['too_permissive'] + $a['too_strict'];
        $pct = static fn(float|int $n, float|int $d): ?float => $d > 0 ? round($n / $d, 4) : null;
        $avg = static fn(float $sum, int $n): ?float => $n > 0 ? round($sum / $n, 4) : null;

        return [
            'uses'            => $a['uses'],
            'comments'        => count($a['comments']),
            'prod_uses'       => $a['prod_uses'],
            'dev_uses'        => $a['dev_uses'],
            'first_used'      => $a['first_used'],
            'last_used'       => $a['last_used'],
            'by_decision'     => $a['by_decision'],
            'decisive'        => $a['decisive'],
            'errors'          => $errors,
            'too_permissive'  => $a['too_permissive'],
            'too_strict'      => $a['too_strict'],
            'effectiveness'   => $pct($a['decisive'] - $errors, $a['decisive']),
            'escalated'       => $a['escalated'],
            'escalated_hidden'  => $a['escalated_hidden'],
            'escalated_visible' => $a['escalated_visible'],
            'escalated_pending' => $a['escalated_pending'],
            'confidence_avg'     => $avg($a['conf_sum'], $a['conf_n']),
            'confidence_ok_avg'  => $avg($a['conf_ok_sum'], $a['conf_ok_n']),
            'confidence_err_avg' => $avg($a['conf_err_sum'], $a['conf_err_n']),
            'bands'           => array_map(static fn($b) => $b + [
                'error_rate'      => $b['n'] > 0 ? round($b['errors'] / $b['n'], 4) : null,
                'permissive_rate' => $b['n'] > 0 ? round($b['too_permissive'] / $b['n'], 4) : null,
                'strict_rate'     => $b['n'] > 0 ? round($b['too_strict'] / $b['n'], 4) : null,
            ], $a['bands']),
        ];
    }
}
