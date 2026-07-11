<?php

namespace Kanboard\Plugin\Kensho\Model;

/**
 * KenshoScoreModel — deterministic, transparent alignment math (spec §5).
 *
 * Turns user-defined areas + aggregated activity dimensions into per-area
 * importance-share vs actual-effort-share, a signed discrepancy, and an OPTIONAL
 * bounded 0–100 composite score (= 100 × the overlap between the intended and
 * actual effort distributions). Pure functions, no I/O — the whole thing is a
 * chosen proxy the user can inspect and contest, never a measurement of worth.
 *
 * Assignment: each dimension is claimed by the FIRST area (in order) whose
 * sources contain its (type,id); unclaimed dimensions form an implicit
 * "Unassigned" bucket. This guarantees Σ actual_share = 100 with no double count.
 */
class KenshoScoreModel
{
    const UNASSIGNED_ID = '__unassigned__';

    public static function alignment(array $areas, array $dimensions): array
    {
        // 1. Effort per dimension (time, or task_count fallback when no time logged).
        $totalTime = 0.0;
        foreach ($dimensions as $d) {
            $totalTime += (float) ($d['time_spent'] ?? 0);
        }
        $useCount = $totalTime <= 0.0;

        // 2. Assign each dimension to the first claiming area.
        $areaEffort = [];
        foreach ($areas as $a) {
            $areaEffort[$a['id']] = 0.0;
        }
        $unassignedEffort = 0.0;
        $totalEffort = 0.0;

        foreach ($dimensions as $d) {
            $effort = $useCount ? (float) ($d['task_count'] ?? 0) : (float) ($d['time_spent'] ?? 0);
            $totalEffort += $effort;
            $ownerId = self::firstOwner($areas, (string) ($d['type'] ?? ''), (int) ($d['id'] ?? 0));
            if ($ownerId === null) {
                $unassignedEffort += $effort;
            } else {
                $areaEffort[$ownerId] += $effort;
            }
        }

        // 3. Importance denominator.
        $impDenom = 0.0;
        foreach ($areas as $a) {
            $impDenom += (float) $a['importance'] * (float) ($a['weight'] ?? 1.0);
        }

        // 4. Build rows.
        $rows = [];
        foreach ($areas as $a) {
            $effort = $areaEffort[$a['id']];
            $actualShare = $totalEffort > 0 ? 100.0 * $effort / $totalEffort : 0.0;
            $impShare = $impDenom > 0
                ? 100.0 * ((float) $a['importance'] * (float) ($a['weight'] ?? 1.0)) / $impDenom
                : 0.0;
            $rows[] = [
                'area_id'          => $a['id'],
                'label'            => $a['label'],
                'importance'       => (int) $a['importance'],
                'weight'           => (float) ($a['weight'] ?? 1.0),
                'effort'           => $effort,
                'actual_share'     => round($actualShare, 1),
                'importance_share' => round($impShare, 1),
                'discrepancy'      => round($impShare - $actualShare, 1),
            ];
        }
        if ($unassignedEffort > 0) {
            $actualShare = $totalEffort > 0 ? 100.0 * $unassignedEffort / $totalEffort : 0.0;
            $rows[] = [
                'area_id'          => self::UNASSIGNED_ID,
                'label'            => 'Unassigned',
                'importance'       => 0,
                'weight'           => 0.0,
                'effort'           => $unassignedEffort,
                'actual_share'     => round($actualShare, 1),
                'importance_share' => 0.0,
                'discrepancy'      => round(0.0 - $actualShare, 1),
            ];
        }

        // 5. Composite score (opt-in at the UI; computed here when meaningful).
        $score = null;
        if (! empty($areas) && $totalEffort > 0 && $impDenom > 0) {
            $sumAbs = 0.0;
            foreach ($rows as $row) {
                $sumAbs += abs($row['importance_share'] - $row['actual_share']);
            }
            $score = (int) round(100.0 - 0.5 * $sumAbs);
            $score = max(0, min(100, $score));
        }

        return [
            'uses_task_count_fallback' => $useCount && $totalEffort > 0,
            'total_effort'             => $totalEffort,
            'rows'                     => $rows,
            'score'                    => $score,
        ];
    }

    /** First area (in order) whose sources contain (type,id); null if none. */
    private static function firstOwner(array $areas, string $type, int $id): ?string
    {
        $key = match ($type) {
            'project'  => 'project_ids',
            'category' => 'category_ids',
            'tag'      => 'tag_ids',
            default    => null,
        };
        if ($key === null) {
            return null;
        }
        foreach ($areas as $a) {
            $ids = $a['sources'][$key] ?? [];
            if (is_array($ids) && in_array($id, array_map('intval', $ids), true)) {
                return $a['id'];
            }
        }
        return null;
    }
}
