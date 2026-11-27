<?php

namespace Kanboard\Plugin\Kensho\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * KenshoAiGate — single source of truth for "is AI reflection available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present (with its AiQuota spend guard,
 * 1.3.0+) AND ProviderRegistry::resolve() (model routing, 1.4.0+) AND
 * ProviderRegistry::isReady(). An older AiConnector keeps AI off.
 * Consulted identically by Plugin/controller (to hide AI actions) and by every
 * AI route guard, so the UI and the guards never diverge. When closed, Kensho
 * stays fully functional in manual mode.
 */
class KenshoAiGate
{
    /**
     * @param \Pimple\Container $container
     * @param int|null  $phpVersionId     PHP_VERSION_ID override (tests).
     * @param bool|null $connectorPresent AiConnector-present override (tests).
     * @param string|null $registryClass  ProviderRegistry class override (tests).
     */
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null, ?string $registryClass = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? (class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class)
                && class_exists(\Kanboard\Plugin\AiConnector\Model\AiQuota::class));
        if (! $present) {
            return false;
        }

        // Model routing (AiConnector 1.4.0+): without resolve() no feature can pick a model.
        $registryClass ??= ProviderRegistry::class;
        if (! method_exists($registryClass, 'resolve')) {
            return false;
        }

        return (new $registryClass($container))->isReady();
    }
}
