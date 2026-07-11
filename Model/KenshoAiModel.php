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

        $raw = $this->safeStructured($messages, $schema, $profileId);

        return [
            'observations' => self::stringList($raw['observations'] ?? []),
            'questions'    => self::stringList($raw['questions'] ?? []),
            'gentle_note'  => is_string($raw['gentle_note'] ?? null) ? $raw['gentle_note'] : '',
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    /**
     * Call structured() defensively. Returns [] on any provider error — the
     * caller normalizes []. Never logs the message (may carry provider details).
     */
    protected function safeStructured(array $messages, string $schema, ?string $profileId): array
    {
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
