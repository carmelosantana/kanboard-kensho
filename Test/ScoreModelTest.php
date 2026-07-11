<?php

require_once 'tests/units/Base.php';

use Kanboard\Plugin\Kensho\Model\KenshoScoreModel;
use KanboardTests\units\Base;

class ScoreModelTest extends Base
{
    private function areas(): array
    {
        return [
            ['id' => 'area_health', 'label' => 'Health', 'importance' => 9, 'weight' => 1.0,
             'sources' => ['project_ids' => [1], 'category_ids' => [], 'tag_ids' => []]],
            ['id' => 'area_work', 'label' => 'Work', 'importance' => 5, 'weight' => 1.0,
             'sources' => ['project_ids' => [2], 'category_ids' => [], 'tag_ids' => []]],
        ];
    }

    public function testSharesSumTo100AndDiscrepancySigns()
    {
        $dims = [
            ['type' => 'project', 'id' => 1, 'label' => 'Gym',   'task_count' => 2, 'time_spent' => 10.0],
            ['type' => 'project', 'id' => 2, 'label' => 'Job',   'task_count' => 8, 'time_spent' => 90.0],
        ];
        $r = KenshoScoreModel::alignment($this->areas(), $dims);

        $this->assertFalse($r['uses_task_count_fallback']);
        $sumActual = array_sum(array_column($r['rows'], 'actual_share'));
        $this->assertEqualsWithDelta(100.0, $sumActual, 0.001);
        $sumImp = array_sum(array_column($r['rows'], 'importance_share'));
        $this->assertEqualsWithDelta(100.0, $sumImp, 0.001);

        // Health: importance_share = 100*9/14 ≈ 64.3; actual = 10%. discrepancy positive (under-invested).
        $health = $this->rowById($r['rows'], 'area_health');
        $this->assertEqualsWithDelta(10.0, $health['actual_share'], 0.001);
        $this->assertGreaterThan(0, $health['discrepancy']);
        // Work: over-invested → discrepancy negative.
        $work = $this->rowById($r['rows'], 'area_work');
        $this->assertLessThan(0, $work['discrepancy']);

        $this->assertGreaterThanOrEqual(0, $r['score']);
        $this->assertLessThanOrEqual(100, $r['score']);
    }

    public function testTaskCountFallbackWhenNoTimeLogged()
    {
        $dims = [
            ['type' => 'project', 'id' => 1, 'label' => 'Gym', 'task_count' => 3, 'time_spent' => 0.0],
            ['type' => 'project', 'id' => 2, 'label' => 'Job', 'task_count' => 1, 'time_spent' => 0.0],
        ];
        $r = KenshoScoreModel::alignment($this->areas(), $dims);
        $this->assertTrue($r['uses_task_count_fallback']);
        // effort from counts: Health 3/4 = 75%, Work 1/4 = 25%.
        $this->assertEqualsWithDelta(75.0, $this->rowById($r['rows'], 'area_health')['actual_share'], 0.001);
    }

    public function testUnassignedBucketAppears()
    {
        $dims = [
            ['type' => 'project', 'id' => 1,  'label' => 'Gym',    'task_count' => 1, 'time_spent' => 20.0],
            ['type' => 'project', 'id' => 99, 'label' => 'Random', 'task_count' => 1, 'time_spent' => 80.0],
        ];
        $r = KenshoScoreModel::alignment($this->areas(), $dims);
        $un = $this->rowById($r['rows'], '__unassigned__');
        $this->assertNotNull($un);
        $this->assertEqualsWithDelta(80.0, $un['actual_share'], 0.001);
        $this->assertEquals(0, $un['importance']);
        $this->assertEqualsWithDelta(0.0, $un['importance_share'], 0.001);
    }

    public function testNoAreasScoreNull()
    {
        $dims = [['type' => 'project', 'id' => 1, 'label' => 'Gym', 'task_count' => 1, 'time_spent' => 5.0]];
        $r = KenshoScoreModel::alignment([], $dims);
        $this->assertNull($r['score']);
    }

    public function testNoEffortScoreNull()
    {
        $r = KenshoScoreModel::alignment($this->areas(), []);
        $this->assertNull($r['score']);
        $this->assertEqualsWithDelta(0.0, $r['total_effort'], 0.001);
        $this->assertFalse($r['uses_task_count_fallback']);
    }

    public function testOverlappingSourcesClaimedByFirstAreaOnly()
    {
        // Both areas list project 1 in their sources — the FIRST area (in array
        // order) must claim all of its effort; the second area gets none of it,
        // and no effort is double-counted (Σ actual_share still = 100).
        $areas = [
            ['id' => 'area_health', 'label' => 'Health', 'importance' => 9, 'weight' => 1.0,
             'sources' => ['project_ids' => [1], 'category_ids' => [], 'tag_ids' => []]],
            ['id' => 'area_work', 'label' => 'Work', 'importance' => 5, 'weight' => 1.0,
             'sources' => ['project_ids' => [1, 2], 'category_ids' => [], 'tag_ids' => []]],
        ];
        $dims = [
            ['type' => 'project', 'id' => 1, 'label' => 'Gym', 'task_count' => 2, 'time_spent' => 10.0],
            ['type' => 'project', 'id' => 2, 'label' => 'Job', 'task_count' => 8, 'time_spent' => 90.0],
        ];
        $r = KenshoScoreModel::alignment($areas, $dims);

        $health = $this->rowById($r['rows'], 'area_health');
        $work = $this->rowById($r['rows'], 'area_work');
        // Health (first) claims project 1's 10.0 of effort entirely.
        $this->assertEqualsWithDelta(10.0, $health['effort'], 0.001);
        // Work (second) does NOT also get project 1's effort — only project 2's 90.0.
        $this->assertEqualsWithDelta(90.0, $work['effort'], 0.001);

        $sumActual = array_sum(array_column($r['rows'], 'actual_share'));
        $this->assertEqualsWithDelta(100.0, $sumActual, 0.001);
    }

    public function testPerfectAlignmentScores100()
    {
        // Two areas equal importance; effort split matches importance split exactly.
        $areas = [
            ['id' => 'a', 'label' => 'A', 'importance' => 5, 'weight' => 1.0, 'sources' => ['project_ids' => [1], 'category_ids' => [], 'tag_ids' => []]],
            ['id' => 'b', 'label' => 'B', 'importance' => 5, 'weight' => 1.0, 'sources' => ['project_ids' => [2], 'category_ids' => [], 'tag_ids' => []]],
        ];
        $dims = [
            ['type' => 'project', 'id' => 1, 'label' => 'A', 'task_count' => 1, 'time_spent' => 50.0],
            ['type' => 'project', 'id' => 2, 'label' => 'B', 'task_count' => 1, 'time_spent' => 50.0],
        ];
        $r = KenshoScoreModel::alignment($areas, $dims);
        $this->assertEquals(100, $r['score']);
    }

    public function testScoreNeverBelowZero()
    {
        // Maximal mismatch: all importance on an area with zero effort, all effort unassigned.
        $areas = [
            ['id' => 'a', 'label' => 'A', 'importance' => 10, 'weight' => 1.0, 'sources' => ['project_ids' => [1], 'category_ids' => [], 'tag_ids' => []]],
        ];
        $dims = [
            ['type' => 'project', 'id' => 99, 'label' => 'Other', 'task_count' => 1, 'time_spent' => 100.0],
        ];
        $r = KenshoScoreModel::alignment($areas, $dims);
        $this->assertGreaterThanOrEqual(0, $r['score']);
    }

    private function rowById(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ($row['area_id'] === $id) {
                return $row;
            }
        }
        return null;
    }
}
