<?php

namespace Kanboard\Plugin\Kensho\Model;

use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * KenshoAiGate — single source of truth for "is AI reflection available?".
 *
 * Gate = PHP >= 8.4 AND AiConnector present AND ProviderRegistry::isReady().
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
     */
    public static function isReady($container, ?int $phpVersionId = null, ?bool $connectorPresent = null): bool
    {
        $versionId = $phpVersionId ?? PHP_VERSION_ID;
        if ($versionId < 80400) {
            return false;
        }

        $present = $connectorPresent
            ?? class_exists(\Kanboard\Plugin\AiConnector\Model\ProviderRegistry::class);
        if (! $present) {
            return false;
        }

        return (new ProviderRegistry($container))->isReady();
    }
}
