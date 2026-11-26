<?php

namespace Kanboard\Plugin\Kensho\Model;

use Kanboard\Core\Base;

/**
 * KenshoAiModel — the three opt-in AiConnector calls. Each builds a messages
 * array + a JSON Schema string, calls ProviderRegistry::structured() (BLOCKING,
 * returns a decoded array), and normalizes the result to a guaranteed shape so
 * the surfaces never crash on a malformed model response.
 *
 * Guardrails (spec §Product-principles) are encoded in every system prompt:
 *  - gentle, non-authoritative, second person;
 *  - CHALLENGE rather than flatter (counter sycophancy);
 *  - treat low/absent activity as an ordinary lapse, never a failure;
 *  - never claim the user's "purpose" or worth; defer to the user's own meaning.
 *
 * The AI proposes; the user disposes — nothing here persists anything.
 */
class KenshoAiModel extends Base
{
    /**
     * Reflect on computed discrepancies + the user's identity statements.
     * Returns gentle observations + questions the user answers themselves.
     * By construction there is NO verdict/score/rating field.
     *
     * @param array $discrepancies [{area_id, importance_share, actual_share, discrepancy}, ...]
     * @param array $identities    map area_id => desired-self statement
     * @return array{observations: string[], questions: string[], gentle_note: string}
     */
    public function reflect(array $discrepancies, array $identities, ?string $profileId = null): array
    {
        $schema = json_encode([
            'type'       => 'object',
            'properties' => [
                'observations' => ['type' => 'array', 'items' => ['type' => 'string']],
                'questions'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'gentle_note'  => ['type' => 'string'],
            ],
            'required'   => ['observations', 'questions', 'gentle_note'],
        ]);

        $payload = [
            'discrepancies' => $discrepancies,
            'identities'    => $identities,
        ];

        $messages = [
            ['role' => 'system', 'content' => self::systemReflect()],
            ['role' => 'user',   'content' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];

        $raw = $this->safeStructured($messages, $schema, $profileId, 'kensho.reflect');

        return [
            'observations' => self::stringList($raw['observations'] ?? []),
            'questions'    => self::stringList($raw['questions'] ?? []),
            'gentle_note'  => is_string($raw['gentle_note'] ?? null) ? $raw['gentle_note'] : '',
        ];
    }

    /**
     * Propose candidate life-area groupings from the aggregated dimensions.
     * Input carries dimension labels + signals + up to 5 sample TITLES only
     * (no descriptions/comments). Output is a scaffold the user edits/confirms.
     *
     * @return array{areas: array<int, array{label:string, rationale:string, dimension_refs: array<int, array{type:string,id:int}>, importance_hint: int|null}>}
     */
    public function proposeAreas(array $aggregate, ?string $profileId = null): array
    {
        $schema = json_encode([
            'type'       => 'object',
            'properties' => [
                'areas' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'label'          => ['type' => 'string'],
                            'rationale'      => ['type' => 'string'],
                            'dimension_refs' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => ['project', 'category', 'tag']],
                                        'id'   => ['type' => 'integer'],
                                    ],
                                    'required'   => ['type', 'id'],
                                ],
                            ],
                            'importance_hint' => ['type' => 'integer'],
                        ],
                        'required'   => ['label', 'rationale', 'dimension_refs'],
                    ],
                ],
            ],
            'required'   => ['areas'],
        ]);

        $messages = [
            ['role' => 'system', 'content' => self::systemPropose()],
            ['role' => 'user',   'content' => json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
        ];

        $raw = $this->safeStructured($messages, $schema, $profileId, 'kensho.propose');
        $areas = [];
        foreach ((is_array($raw['areas'] ?? null) ? $raw['areas'] : []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $label = trim((string) ($a['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $refs = [];
            foreach ((is_array($a['dimension_refs'] ?? null) ? $a['dimension_refs'] : []) as $r) {
                if (is_array($r) && isset($r['type'], $r['id'])) {
                    $refs[] = ['type' => (string) $r['type'], 'id' => (int) $r['id']];
                }
            }
            $areas[] = [
                'label'           => $label,
                'rationale'       => trim((string) ($a['rationale'] ?? '')),
                'dimension_refs'  => $refs,
                'importance_hint' => isset($a['importance_hint']) && is_numeric($a['importance_hint'])
                    ? max(1, min(10, (int) $a['importance_hint'])) : null,
            ];
        }
        return ['areas' => $areas];
    }

    /**
     * Turn a rough note about an area into a crisper desired-self statement +
     * concrete goals + one reflection question. Edit-before-save scaffold.
     *
     * @return array{identity:string, goals: string[], reflection_question:string}
     */
    public function clarifyGoals(string $areaLabel, string $note, ?string $profileId = null): array
    {
        $schema = json_encode([
            'type'       => 'object',
            'properties' => [
                'identity'            => ['type' => 'string'],
                'goals'               => ['type' => 'array', 'items' => ['type' => 'string']],
                'reflection_question' => ['type' => 'string'],
            ],
            'required'   => ['identity', 'goals', 'reflection_question'],
        ]);

        $messages = [
            ['role' => 'system', 'content' => self::systemClarify()],
            ['role' => 'user',   'content' => json_encode(['area' => $areaLabel, 'note' => $note], JSON_UNESCAPED_UNICODE)],
        ];

        $raw = $this->safeStructured($messages, $schema, $profileId, 'kensho.clarify');
        return [
            'identity'            => is_string($raw['identity'] ?? null) ? trim($raw['identity']) : '',
            'goals'               => self::stringList($raw['goals'] ?? []),
            'reflection_question' => is_string($raw['reflection_question'] ?? null) ? trim($raw['reflection_question']) : '',
        ];
    }

    protected static function systemPropose(): string
    {
        return implode(' ', [
            'You are helping someone group their own recorded activity (projects, categories, tags, with effort signals and a few sample task titles) into life areas that are meaningful TO THEM.',
            'Return only suggestions with short rationales; these are a starting point the person will edit, rename, merge, or reject — the areas are theirs, not yours to impose.',
            'Do not use a fixed values taxonomy; follow the shape of their actual data and their language.',
            'Never state or imply the person\'s purpose, calling, or worth, and do not rank areas as more or less worthy.',
            'Challenge vague or self-flattering framing in the data rather than validating it uncritically.',
            'An importance_hint, if given, is a gentle guess they will overwrite; keep rationales observational, not flattering.',
            'Treat low or absent activity in a dimension as an ordinary lapse, never a failure or something to correct.',
        ]);
    }

    protected static function systemClarify(): string
    {
        return implode(' ', [
            'You help someone sharpen a rough note about one life area into a clear, second-person desired-self statement and a few concrete, doable goals, plus one open reflection question.',
            'Stay in their own words and meaning; do not inflate, moralize, or promise outcomes.',
            'Challenge gently where the note is vague rather than flattering it; keep goals small and humane.',
            'Never claim what their purpose or worth is. The output is a draft they will edit before anything is saved.',
            'Treat low or absent activity as an ordinary lapse, never a failure or something to correct.',
        ]);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Call structured() defensively. Returns [] on any provider error — the
     * caller normalizes []. Never logs the message (may carry provider details).
     *
     * AiConnector's shared quota and input cap are checked first, with the
     * text that will be sent; a refusal throws KenshoAiRefused and the provider
     * is not called.
     *
     * @throws KenshoAiRefused
     */
    protected function safeStructured(array $messages, string $schema, ?string $profileId, string $feature): array
    {
        $refusal = (new \Kanboard\Plugin\AiConnector\Model\AiQuota($this->container))
            ->consume((int) $this->userSession->getId(), $feature, array_column($messages, 'content'));
        if ($refusal !== null) {
            throw new KenshoAiRefused($refusal);
        }

        try {
            $out = $this->getRegistry()->structured($messages, $schema, $profileId);
            return is_array($out) ? $out : [];
        } catch (\Throwable $e) {
            error_log('[Kensho] Provider error: ' . get_class($e) . ' code ' . $e->getCode());
            return [];
        }
    }

    /** Factory for the AiConnector registry — overridable in tests (no network). */
    protected function getRegistry()
    {
        return new \Kanboard\Plugin\AiConnector\Model\ProviderRegistry($this->container);
    }

    /** Coerce a value to a list of non-empty trimmed strings. */
    protected static function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[] = trim($v);
            }
        }
        return $out;
    }

    protected static function systemReflect(): string
    {
        return implode(' ', [
            'You are a gentle, second-person reflection companion. The person has recorded life areas they care about, how important each is to them, and who they want to be, and you are shown the gap between stated importance and where their logged effort actually went.',
            'Offer a few plain observations and a few open questions they can answer themselves.',
            'CHALLENGE rather than flatter: do not praise or reassure reflexively; gently surface tensions and alternative readings so they think, not so they feel validated.',
            'Treat low or absent activity as an ordinary lapse, never a failure or a moral judgment — quiet periods are normal.',
            'Never state or imply what their purpose, calling, or worth is; you have no authority to pronounce that. Defer entirely to their own meaning and let them draw conclusions.',
            'Output only observations, questions, and one short gentle note. Do not output any verdict, score, rating, or recommendation.',
        ]);
    }
}
