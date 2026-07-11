<?php

namespace Kanboard\Plugin\Kensho\Model;

use Kanboard\Core\Base;

/**
 * KenshoProfileModel — per-user Kensho profile stored as JSON in user_metadata
 * (key `kensho_profile`). No DB migration. Decoding is defensive: corrupt or
 * absent JSON, or a partial object, always yields a complete default-filled
 * profile so the surfaces never see a missing key.
 */
class KenshoProfileModel extends Base
{
    const KEY     = 'kensho_profile';
    const VERSION = 1;

    public static function defaults(): array
    {
        return [
            'version'     => self::VERSION,
            'window_days' => 90,
            'settings'    => ['show_score' => true],
            'areas'       => [],
            'reflections' => [],
        ];
    }

    public function getProfile(int $userId): array
    {
        $raw = (string) $this->userMetadataModel->get($userId, self::KEY, '');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return self::defaults();
        }
        return self::sanitize($data);
    }

    public function saveProfile(int $userId, array $profile): bool
    {
        $clean = self::sanitize($profile);
        return (bool) $this->userMetadataModel->save($userId, [
            self::KEY => json_encode($clean, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function upsertArea(int $userId, array $area): array
    {
        $profile = $this->getProfile($userId);
        $area = self::sanitizeArea($area, $profile['areas']);

        $found = false;
        foreach ($profile['areas'] as $i => $existing) {
            if ($existing['id'] === $area['id']) {
                $profile['areas'][$i] = $area;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $profile['areas'][] = $area;
        }

        $this->saveProfile($userId, $profile);
        return $this->getProfile($userId);
    }

    public function removeArea(int $userId, string $areaId): array
    {
        $profile = $this->getProfile($userId);
        $profile['areas'] = array_values(array_filter(
            $profile['areas'],
            fn ($a) => $a['id'] !== $areaId
        ));
        $this->saveProfile($userId, $profile);
        return $this->getProfile($userId);
    }

    public function addReflection(int $userId, array $reflection): array
    {
        $profile = $this->getProfile($userId);
        $profile['reflections'][] = self::sanitizeReflection($reflection);
        $this->saveProfile($userId, $profile);
        return $this->getProfile($userId);
    }

    // ── Sanitizers (defensive; fill defaults, clamp ranges) ────────────────────

    private static function sanitize(array $data): array
    {
        $d = self::defaults();
        $windowDays = (int) ($data['window_days'] ?? $d['window_days']);
        $settingsRaw = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $out = [
            'version'     => self::VERSION,
            'window_days' => in_array($windowDays, [30, 90, 180, 365], true) ? $windowDays : 90,
            'settings'    => [
                'show_score' => (bool) ($settingsRaw['show_score'] ?? true),
            ],
            'areas'       => [],
            'reflections' => [],
        ];

        if (isset($data['areas']) && is_array($data['areas'])) {
            foreach ($data['areas'] as $a) {
                if (is_array($a)) {
                    $out['areas'][] = self::sanitizeArea($a, $out['areas']);
                }
            }
        }
        if (isset($data['reflections']) && is_array($data['reflections'])) {
            foreach ($data['reflections'] as $r) {
                if (is_array($r)) {
                    $out['reflections'][] = self::sanitizeReflection($r);
                }
            }
        }
        return $out;
    }

    private static function sanitizeArea(array $a, array $siblings): array
    {
        $label = trim((string) ($a['label'] ?? ''));
        $id = trim((string) ($a['id'] ?? ''));
        if ($id === '') {
            $id = self::uniqueSlug($label, $siblings);
        }
        $importance = (int) ($a['importance'] ?? 5);
        $importance = max(1, min(10, $importance));

        $goals = [];
        if (isset($a['goals']) && is_array($a['goals'])) {
            foreach ($a['goals'] as $g) {
                if (is_string($g) && trim($g) !== '') {
                    $goals[] = trim($g);
                }
            }
        }

        $srcRaw = is_array($a['sources'] ?? null) ? $a['sources'] : [];
        $sources = [
            'project_ids'  => self::intList($srcRaw['project_ids'] ?? []),
            'category_ids' => self::intList($srcRaw['category_ids'] ?? []),
            'tag_ids'      => self::intList($srcRaw['tag_ids'] ?? []),
        ];

        $weight = isset($a['weight']) && is_numeric($a['weight']) ? (float) $a['weight'] : 1.0;
        if ($weight < 0) {
            $weight = 1.0;
        }

        return [
            'id'         => $id,
            'label'      => $label,
            'importance' => $importance,
            'identity'   => trim((string) ($a['identity'] ?? '')),
            'goals'      => $goals,
            'weight'     => $weight,
            'sources'    => $sources,
        ];
    }

    private static function sanitizeReflection(array $r): array
    {
        return [
            'id'               => trim((string) ($r['id'] ?? ('r_' . substr(md5(json_encode($r)), 0, 10)))),
            'date'             => (int) ($r['date'] ?? 0),
            'window_days'      => (int) ($r['window_days'] ?? 90),
            'score'            => isset($r['score']) && is_numeric($r['score']) ? (int) $r['score'] : null,
            'discrepancies'    => is_array($r['discrepancies'] ?? null) ? $r['discrepancies'] : [],
            'narrative'        => (string) ($r['narrative'] ?? ''),
            'provider_profile' => (string) ($r['provider_profile'] ?? ''),
        ];
    }

    private static function intList($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }
        return array_values(array_unique($out));
    }

    private static function uniqueSlug(string $label, array $siblings): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'area';
        }
        $slug = 'area_' . $base;
        $existing = array_column($siblings, 'id');
        $candidate = $slug;
        $n = 2;
        while (in_array($candidate, $existing, true)) {
            $candidate = $slug . '_' . $n;
            $n++;
        }
        return $candidate;
    }
}
