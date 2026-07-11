<?php

require_once 'tests/units/Base.php';

use Kanboard\Model\ProjectModel;
use Kanboard\Model\CategoryModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFinderModel;
use Kanboard\Plugin\Kensho\Model\KenshoDataModel;
use KanboardTests\units\Base;

class DataModelTest extends Base
{
    /** Force a task's date_modification to an absolute epoch (simulate age). */
    private function setModified(int $taskId, int $epoch): void
    {
        $this->container['db']->table('tasks')->eq('id', $taskId)->update(['date_modification' => $epoch]);
    }

    public function testAggregateIsWindowAndAccessFiltered()
    {
        $projectModel = new ProjectModel($this->container);
        $categoryModel = new CategoryModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);

        // Accessible project owned by admin (id 1). Base creates admin as user 1.
        // NOTE: ProjectModel::create($values, $userId, $addUser) takes owner/user as
        // POSITIONAL args and unconditionally overwrites $values['owner_id'] with $userId
        // (defaulting to 0) — an 'owner_id' key inside $values is ignored. $addUser=true
        // is required to actually insert the project_has_users row that
        // getActiveProjectIds() reads; without it the project would never be "accessible".
        $accessibleId = $projectModel->create(['name' => 'Accessible'], 1, true);
        $catId = $categoryModel->create(['name' => 'Fitness', 'project_id' => $accessibleId]);

        // In-window task (owner 1), with time + category.
        $inWindow = $taskCreation->create([
            'title' => 'Morning run', 'project_id' => $accessibleId, 'owner_id' => 1,
            'category_id' => $catId, 'time_spent' => 5, 'time_estimated' => 6,
        ]);
        // Out-of-window task (very old date_modification).
        $old = $taskCreation->create([
            'title' => 'Ancient task', 'project_id' => $accessibleId, 'owner_id' => 1,
            'time_spent' => 100,
        ]);
        $this->setModified($old, 1000000); // 1970-ish → far outside any window

        $agg = (new KenshoDataModel($this->container))->aggregate(1, 90);

        $projectDim = $this->dim($agg['dimensions'], 'project', $accessibleId);
        $this->assertNotNull($projectDim);
        $this->assertEquals(1, $projectDim['task_count']);          // old task excluded
        $this->assertEqualsWithDelta(5.0, $projectDim['time_spent'], 0.001);
        $this->assertContains('Morning run', $projectDim['sample_titles']);

        $catDim = $this->dim($agg['dimensions'], 'category', $catId);
        $this->assertNotNull($catDim);
        $this->assertEquals(1, $catDim['task_count']);
        $this->assertEquals('Fitness', $catDim['label']);
    }

    public function testTasksInInaccessibleProjectsExcluded()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);

        // A private project "owned" by user 1 (real owner_id row) but WITHOUT a
        // project_has_users membership row (addUser=false) → getActiveProjectIds(1) will
        // not return it, since access is governed by membership, not the owner_id column.
        // This simulates a project the user has no access to without needing a second user.
        $otherId = $projectModel->create(['name' => 'Other', 'is_private' => 1], 1, false);
        $t = $taskCreation->create(['title' => 'Hidden', 'project_id' => $otherId, 'owner_id' => 1, 'time_spent' => 50]);

        // Sanity: getActiveProjectIds returns the accessible set; the aggregate must only include those ids.
        $accessible = $this->container['projectPermissionModel']->getActiveProjectIds(1);
        $this->assertNotContains($otherId, $accessible);

        $agg = (new KenshoDataModel($this->container))->aggregate(1, 90);
        $projectDimIds = array_column(array_filter($agg['dimensions'], fn ($d) => $d['type'] === 'project'), 'id');
        $this->assertNotContains($otherId, $projectDimIds, 'inaccessible project must contribute no dimension');
        foreach ($agg['dimensions'] as $d) {
            if ($d['type'] === 'project') {
                $this->assertContains($d['id'], $accessible);
            }
        }
    }

    public function testTagsAggregated()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreation = new TaskCreationModel($this->container);
        $pid = $projectModel->create(['name' => 'Tagged'], 1, true);
        $tid = $taskCreation->create([
            'title' => 'Tagged task', 'project_id' => $pid, 'owner_id' => 1,
            'tags' => ['deep-work'], 'time_spent' => 3,
        ]);

        $agg = (new KenshoDataModel($this->container))->aggregate(1, 90);
        $tagDims = array_filter($agg['dimensions'], fn ($d) => $d['type'] === 'tag');
        $this->assertNotEmpty($tagDims);
        $labels = array_column($tagDims, 'label');
        $this->assertContains('deep-work', $labels);
    }

    private function dim(array $dims, string $type, int $id): ?array
    {
        foreach ($dims as $d) {
            if ($d['type'] === $type && $d['id'] === $id) {
                return $d;
            }
        }
        return null;
    }
}
