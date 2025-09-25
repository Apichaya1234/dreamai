<?php
/**
 * src/Analytics.php
 * Experiment analytics for DreamAI (PHP 8+), dependency-free.
 *
 * What this module does:
 *  1) Record pairwise results (winner vs losers) for each session
 *  2) Maintain per-arm Elo ratings in `model_ratings`
 *  3) Provide helpers to rebuild Elo from scratch, compute win-rates, and list a leaderboard
 *
 * Terminology:
 *  - "arm" = a source/model/param setting producing an option, identified by arm_key:
 *      * database option:                  "db"
 *      * GPT arms (examples):              "gpt-4o|0.5|0.8", "gpt-5|0.5|0.8"
 *    We use temperature/top_p rounded to 2 decimals and trim trailing zeros (e.g., 0.50 → "0.5").
 *
 * Tables used:
 *  - generated_options(id, source, model, temperature, top_p, ...)
 *  - presentation_sessions(id, A_option_id, B_option_id, C_option_id, D_option_id, ...)
 *  - pairwise_results(id, session_id, winner_option_id, loser_option_id, ...)
 *  - model_ratings(arm_key UNIQUE, rating, rating_dev, sample_size, updated_at)
 *
 * Usage (current flow):
 *  - In select.php after user chooses a slot:
 *      Analytics::recordPairwise($db, $sessionId, $chosenId, $loserIds);
 *      Analytics::applyEloForSelection($db, $sessionId, $chosenId);   // optional but recommended
 *
 * Optional maintenance:
 *  - Rebuild Elo (e.g., after data backfill):  Analytics::rebuildEloFromScratch($db)
 *  - Get top arms:                              Analytics::leaderboard($db, 20)
 *  - Win-rate snapshot:                         Analytics::winRates($db)
 */

declare(strict_types=1);

namespace DreamAI;

final class Analytics
{
    /** Default Elo params */
    private const ELO_DEFAULT   = 1500.0;
    private const ELO_DEV       = 350.0;     // stored for future Glicko if needed
    private const ELO_K         = 32.0;      // global K-factor
    private const FLOAT_FORMAT  = 2;         // for temp/top_p rounding in arm_key

    private function __construct() {}

    // ======================================================================
    // 1) Pairwise recording
    // ======================================================================

    /**
     * Insert 1-vs-1 rows for (winner vs each loser).
     * This function only writes to `pairwise_results`. Elo update is separate.
     *
     * @param DB    $db
     * @param int   $sessionId
     * @param int   $winnerOptionId
     * @param int[] $loserOptionIds
     */
    public static function recordPairwise(DB $db, int $sessionId, int $winnerOptionId, array $loserOptionIds): void
    {
        foreach ($loserOptionIds as $loser) {
            $db->execStmt(
                'INSERT INTO pairwise_results(session_id, winner_option_id, loser_option_id) VALUES (?,?,?)',
                [$sessionId, $winnerOptionId, (int)$loser]
            );
        }
    }

    // ======================================================================
    // 2) Elo updates
    // ======================================================================

    /**
     * Apply Elo updates for a selection event (winner vs the other three).
     * Uses current ratings in `model_ratings`; creates default rows on demand.
     *
     * @param DB  $db
     * @param int $sessionId
     * @param int $winnerOptionId
     * @param float|null $k  Optional custom K; default self::ELO_K
     */
    public static function applyEloForSelection(DB $db, int $sessionId, int $winnerOptionId, ?float $k = null): void
    {
        $k = $k ?? self::ELO_K;

        // 1) Fetch the 4 option ids in this session
        $row = $db->one(
            'SELECT A_option_id, B_option_id, C_option_id, D_option_id FROM presentation_sessions WHERE id=?',
            [$sessionId]
        );
        if (!$row) return;

        $all = [
            (int)$row['A_option_id'],
            (int)$row['B_option_id'],
            (int)$row['C_option_id'],
            (int)$row['D_option_id'],
        ];

        // 2) Split winners & losers
        $winner = (int)$winnerOptionId;
        $losers = array_values(array_filter($all, static fn($id) => $id !== $winner));

        // 3) For each loser, update Elo (winner wins 1-0 vs loser)
        foreach ($losers as $loser) {
            $wa = self::armKeyForOption($db, $winner);
            $lb = self::armKeyForOption($db, $loser);
            if ($wa === null || $lb === null) {
                continue; // skip if arm cannot be determined
            }
            self::eloDuel($db, $wa, $lb, $k);
        }
    }

    /**
     * Run one Elo duel: arm A (winner) defeats arm B (loser).
     * R_a ← R_a + K*(1 - E_a);   R_b ← R_b + K*(0 - E_b)
     */
    private static function eloDuel(DB $db, string $winnerArm, string $loserArm, float $k): void
    {
        // Ensure both rows exist
        $wa = self::ensureModelRating($db, $winnerArm);
        $lb = self::ensureModelRating($db, $loserArm);

        $Ra = (float)$wa['rating'];
        $Rb = (float)$lb['rating'];

        $Ea = self::eloExpected($Ra, $Rb);
        $Eb = self::eloExpected($Rb, $Ra);

        $RaNew = $Ra + $k * (1.0 - $Ea);   // winner gets S=1
        $RbNew = $Rb + $k * (0.0 - $Eb);   // loser  gets S=0

        self::upsertModelRating($db, $winnerArm, $RaNew, ((int)$wa['sample_size']) + 1);
        self::upsertModelRating($db, $loserArm,  $RbNew, ((int)$lb['sample_size']) + 1);
    }

    /** Logistic expectation: 1 / (1 + 10^((R_opp - R_self)/400)) */
    private static function eloExpected(float $Rself, float $Ropp): float
    {
        return 1.0 / (1.0 + pow(10.0, ($Ropp - $Rself) / 400.0));
    }

    // ======================================================================
    // 3) Elo maintenance & snapshots
    // ======================================================================

    /**
     * Rebuild Elo ratings from scratch using the chronological order of pairwise_results.
     * WARNING: This truncates model_ratings and recomputes everything (idempotent).
     */
    public static function rebuildEloFromScratch(DB $db, ?float $k = null): void
    {
        $k = $k ?? self::ELO_K;

        // Reset ratings table (keep structure)
        $db->execStmt('DELETE FROM model_ratings');

        // Walk through pairwise results in time-order
        $rows = $db->all(
            'SELECT pr.winner_option_id, pr.loser_option_id
               FROM pairwise_results pr
              ORDER BY pr.created_at ASC, pr.id ASC'
        );

        foreach ($rows as $r) {
            $wa = self::armKeyForOption($db, (int)$r['winner_option_id']);
            $lb = self::armKeyForOption($db, (int)$r['loser_option_id']);
            if ($wa === null || $lb === null) continue;
            self::eloDuel($db, $wa, $lb, $k);
        }
    }

    /**
     * Compute win-rate snapshot by arm across all pairwise duels.
     * win_rate = wins / (wins + losses)
     *
     * @return array<int, array{arm_key:string, wins:int, losses:int, total:int, win_rate:float}>
     */
    public static function winRates(DB $db): array
    {
        $rows = $db->all(
            'SELECT pr.id,
                    w.source AS w_source, w.temperature AS w_temp, w.top_p AS w_top,
                    l.source AS l_source, l.temperature AS l_temp, l.top_p AS l_top
               FROM pairwise_results pr
               JOIN generated_options w ON w.id = pr.winner_option_id
               JOIN generated_options l ON l.id = pr.loser_option_id'
        );

        $stats = []; // arm_key => ['wins'=>..,'losses'=>..]
        foreach ($rows as $r) {
            $winArm = self::makeArmKey($r['w_source'], $r['w_temp'], $r['w_top']);
            $losArm = self::makeArmKey($r['l_source'], $r['l_temp'], $r['l_top']);

            if (!isset($stats[$winArm])) $stats[$winArm] = ['wins'=>0,'losses'=>0];
            if (!isset($stats[$losArm])) $stats[$losArm] = ['wins'=>0,'losses'=>0];

            $stats[$winArm]['wins']   += 1;
            $stats[$losArm]['losses'] += 1;
        }

        $out = [];
        foreach ($stats as $arm => $st) {
            $wins = (int)$st['wins'];
            $loss = (int)$st['losses'];
            $tot  = $wins + $loss;
            $rate = $tot > 0 ? $wins / $tot : 0.0;
            $out[] = [
                'arm_key'  => $arm,
                'wins'     => $wins,
                'losses'   => $loss,
                'total'    => $tot,
                'win_rate' => $rate,
            ];
        }

        // Sort by win_rate desc, then by total desc
        usort($out, static function($a, $b) {
            if ($a['win_rate'] === $b['win_rate']) {
                return $b['total'] <=> $a['total'];
            }
            return $b['win_rate'] <=> $a['win_rate'];
        });

        return $out;
    }

    /**
     * Leaderboard by Elo rating (desc), then sample_size desc.
     * @return array<int, array{arm_key:string, rating:float, sample_size:int, rating_dev:float, updated_at:string}>
     */
    public static function leaderboard(DB $db, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $db->all(
            "SELECT arm_key, rating, rating_dev, sample_size, updated_at
               FROM model_ratings
              ORDER BY rating DESC, sample_size DESC
              LIMIT {$limit}"
        );

        // Cast types properly
        foreach ($rows as &$r) {
            $r['rating']      = (float)$r['rating'];
            $r['rating_dev']  = (float)$r['rating_dev'];
            $r['sample_size'] = (int)$r['sample_size'];
        }
        return $rows;
    }

    // ======================================================================
    // 4) Internal helpers
    // ======================================================================

    /** Build arm_key from a generated_options row */
    private static function armKeyFromRow(array $row): string
    {
        return self::makeArmKey($row['source'] ?? null, $row['temperature'] ?? null, $row['top_p'] ?? null);
    }

    /** Build arm_key from pieces */
    private static function makeArmKey(?string $source, $temperature, $top_p): string
    {
        $source = (string)$source;
        if ($source === 'db') return 'db';

        $t = self::fmtFloat($temperature);
        $p = self::fmtFloat($top_p);
        return "{$source}|{$t}|{$p}";
    }

    /** Format float to trimmed string (2 decimals → trim trailing zeros) */
    private static function fmtFloat($x): string
    {
        if ($x === null || $x === '' || !is_numeric($x)) return '-';
        $s = number_format((float)$x, self::FLOAT_FORMAT, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /** Fetch generated_options row and return arm_key; null if not found */
    private static function armKeyForOption(DB $db, int $optionId): ?string
    {
        $row = $db->one(
            'SELECT source, model, temperature, top_p FROM generated_options WHERE id=?',
            [$optionId]
        );
        return $row ? self::armKeyFromRow($row) : null;
    }

    /** Ensure a model_ratings row exists; return its current values */
    private static function ensureModelRating(DB $db, string $armKey): array
    {
        // Fast path: try to fetch
        $row = $db->one('SELECT arm_key, rating, rating_dev, sample_size FROM model_ratings WHERE arm_key=?', [$armKey]);
        if ($row) return $row;

        // Insert default
        $db->execStmt(
            'INSERT INTO model_ratings (arm_key, rating, rating_dev, sample_size) VALUES (?,?,?,?)',
            [$armKey, self::ELO_DEFAULT, self::ELO_DEV, 0]
        );
        return [
            'arm_key'     => $armKey,
            'rating'      => self::ELO_DEFAULT,
            'rating_dev'  => self::ELO_DEV,
            'sample_size' => 0,
        ];
    }

    /** Upsert rating for an arm (keeps rating_dev untouched for now) */
    private static function upsertModelRating(DB $db, string $armKey, float $newRating, int $newSamples): void
    {
        // MySQL: ON DUPLICATE KEY UPDATE with UNIQUE(arm_key)
        $db->execStmt(
            'INSERT INTO model_ratings (arm_key, rating, rating_dev, sample_size)
                  VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE rating=VALUES(rating), sample_size=VALUES(sample_size), updated_at=CURRENT_TIMESTAMP',
            [$armKey, $newRating, self::ELO_DEV, $newSamples]
        );
    }
}
