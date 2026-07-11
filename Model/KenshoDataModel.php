<?php

namespace Kanboard\Plugin\Kensho\Model;

use Kanboard\Core\Base;
use Kanboard\Model\TaskModel;
use Kanboard\Model\CategoryModel;
use Kanboard\Model\ProjectActivityModel;

/**
 * KenshoDataModel — read-only aggregation of the CURRENT user's OWN activity
 * into per-dimension signals (project / category / tag). Window-filtered by
 * date_modification and access-filtered by getActiveProjectIds(). No writes.
 *
 * Only aggregated patterns are meaningful — a single task or a quiet week is a
 * weak signal, so callers must never infer identity from one row.
 */
class KenshoDataModel extends Base
{
    public function aggregate(int $userId, int $windowDays): array
    {
        $windowDays = max(1, $windowDays);
        $cutoff = time() - ($windowDays * 86400);

        $projectIds = $this->projectPermissionModel->getActiveProjectIds($userId);
        if (empty($projectIds)) {
            return ['window_days' => $windowDays, 'active_days' => 0, 'dimensions' => []];
        }

        // The user's own tasks in accessible projects within the window (open + closed).
        $tasks = $this->db->table(TaskModel::TABLE)
            ->columns('id', 'project_id', 'category_id', 'time_spent', 'time_estimated', 'is_active', 'date_modification', 'title')
            ->eq('owner_id', $userId)
            ->in('project_id', $projectIds)
            ->gte('date_modification', $cutoff)
            ->findAll();

        $taskIds = array_map(fn ($t) => (int) $t['id'], $tasks);
        $tagsByTask = empty($taskIds) ? [] : $this->taskTagModel->getTagsByTaskIds($taskIds);

        // Include private projects the user owns/has access to — getList(false, false)
        // skips the "None" placeholder AND does not filter out private projects.
        $projectLabels = $this->projectModel->getList(false, false);
        $categoryLabels = $this->categoryLabels($tasks);

        // Accumulate per dimension key "type:id".
        $acc = [];
        foreach ($tasks as $t) {
            $day = (int) floor(((int) $t['date_modification']) / 86400);

            $this->bump($acc, 'project', (int) $t['project_id'], $projectLabels[(int) $t['project_id']] ?? ('#' . $t['project_id']), $t, $day);

            if ((int) $t['category_id'] > 0) {
                $this->bump($acc, 'category', (int) $t['category_id'], $categoryLabels[(int) $t['category_id']] ?? ('#' . $t['category_id']), $t, $day);
            }

            foreach ($tagsByTask[(int) $t['id']] ?? [] as $tag) {
                $this->bump($acc, 'tag', (int) $tag['id'], $tag['name'], $t, $day);
            }
        }

        $dimensions = [];
        foreach ($acc as $entry) {
            $dimensions[] = [
                'type'            => $entry['type'],
                'id'              => $entry['id'],
                'label'           => $entry['label'],
                'task_count'      => $entry['task_count'],
                'time_spent'      => round($entry['time_spent'], 2),
                'time_estimated'  => round($entry['time_estimated'], 2),
                'completion_rate' => $entry['task_count'] > 0 ? round($entry['closed'] / $entry['task_count'], 3) : 0.0,
                'active_days'     => count($entry['days']),
                'sample_titles'   => array_slice($entry['titles'], 0, 5),
            ];
        }

        return [
            'window_days' => $windowDays,
            'active_days' => $this->activeDays($userId, $cutoff),
            'dimensions'  => $dimensions,
        ];
    }

    /** Distinct days with any activity by this user in the window. */
    private function activeDays(int $userId, int $cutoff): int
    {
        $rows = $this->db->table(ProjectActivityModel::TABLE)
            ->columns('date_creation')
            ->eq('creator_id', $userId)
            ->gte('date_creation', $cutoff)
            ->findAll();
        $days = [];
        foreach ($rows as $r) {
            $days[(int) floor(((int) $r['date_creation']) / 86400)] = true;
        }
        return count($days);
    }

    /** id => name for every category id referenced by the task set. */
    private function categoryLabels(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $t) {
            if ((int) $t['category_id'] > 0) {
                $ids[(int) $t['category_id']] = true;
            }
        }
        if (empty($ids)) {
            return [];
        }
        $rows = $this->db->table(CategoryModel::TABLE)
            ->columns('id', 'name')
            ->in('id', array_keys($ids))
            ->findAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    private function bump(array &$acc, string $type, int $id, string $label, array $task, int $day): void
    {
        $key = $type . ':' . $id;
        if (! isset($acc[$key])) {
            $acc[$key] = [
                'type' => $type, 'id' => $id, 'label' => $label,
                'task_count' => 0, 'time_spent' => 0.0, 'time_estimated' => 0.0,
                'closed' => 0, 'days' => [], 'titles' => [],
            ];
        }
        $acc[$key]['task_count']++;
        $acc[$key]['time_spent'] += (float) $task['time_spent'];
        $acc[$key]['time_estimated'] += (float) $task['time_estimated'];
        if ((int) $task['is_active'] === 0) {
            $acc[$key]['closed']++;
        }
        $acc[$key]['days'][$day] = true;
        if (count($acc[$key]['titles']) < 5) {
            $acc[$key]['titles'][] = (string) $task['title'];
        }
    }
}
