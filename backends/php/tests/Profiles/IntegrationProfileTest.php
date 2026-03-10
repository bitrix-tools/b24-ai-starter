<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Profiles;

use App\Service\Telemetry\Profiles\IntegrationProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для IntegrationProfile.
 *
 * Проверяем:
 * - Корректный список атрибутов для интеграций с синхронизацией
 * - Соответствие semantic conventions
 * - Все группы атрибутов присутствуют
 * - Поддержка конфликтов и initial sync
 */
class IntegrationProfileTest extends TestCase
{
    private IntegrationProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new IntegrationProfile();
    }

    public function testGetNameReturnsIntegration(): void
    {
        $this->assertSame('integration', $this->profile->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $description = $this->profile->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('Integrations', $description);
    }

    public function testGetAllowedAttributesReturnsExpectedAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        $expectedAttributes = [
            // Sync Process
            'sync.id',
            'sync.type',
            'sync.direction',
            'sync.trigger',
            'sync.status',
            'sync.start_time',
            'sync.end_time',
            'sync.duration_ms',

            // External System
            'integration.system_name',
            'integration.system_version',
            'integration.auth_type',

            // Data Sync
            'entity.type',
            'entity.bitrix_id',
            'entity.external_id',
            'entity.operation',
            'entity.status',

            // Volumes
            'sync.entities_total',
            'sync.entities_processed',
            'sync.entities_synced',
            'sync.entities_failed',
            'sync.entities_skipped',
            'sync.entities_conflict',

            // Conflict Resolution
            'conflict.type',
            'conflict.resolution',
            'conflict.field',
            'conflict.bitrix_value',
            'conflict.external_value',

            // Performance
            'sync.throughput_eps',
            'sync.avg_entity_duration_ms',
            'sync.batch_size',

            // Initial Sync
            'initial_sync.total_entities',
            'initial_sync.completed_entities',
            'initial_sync.progress_percentage',
            'initial_sync.estimated_time_remaining_ms',
        ];

        foreach ($expectedAttributes as $expectedAttribute) {
            $this->assertContains(
                $expectedAttribute,
                $attributes,
                sprintf('Attribute "%s" is missing in IntegrationProfile', $expectedAttribute),
            );
        }
    }

    public function testAllAttributesFollowSemanticConventions(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        foreach ($attributes as $attribute) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9._]*[a-z0-9]$/',
                $attribute,
                sprintf('Attribute "%s" does not follow semantic conventions', $attribute),
            );
        }
    }

    public function testSyncProcessAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $syncAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'sync.'));

        $this->assertNotEmpty($syncAttributes, 'Sync process attributes group is missing');
        $this->assertGreaterThanOrEqual(8, count($syncAttributes));
    }

    public function testIntegrationSystemAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $integrationAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'integration.'));

        $this->assertNotEmpty($integrationAttributes, 'Integration system attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($integrationAttributes));
    }

    public function testEntityAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $entityAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'entity.'));

        $this->assertNotEmpty($entityAttributes, 'Entity attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($entityAttributes));
    }

    public function testConflictResolutionAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $conflictAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'conflict.'));

        $this->assertNotEmpty($conflictAttributes, 'Conflict resolution attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($conflictAttributes));
    }

    public function testInitialSyncAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $initialSyncAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'initial_sync.'));

        $this->assertNotEmpty($initialSyncAttributes, 'Initial sync attributes group is missing');
        $this->assertGreaterThanOrEqual(4, count($initialSyncAttributes));
    }

    public function testSupportsVolumeMetrics(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для отслеживания объёмов синхронизации
        $this->assertContains('sync.entities_total', $attributes);
        $this->assertContains('sync.entities_processed', $attributes);
        $this->assertContains('sync.entities_synced', $attributes);
        $this->assertContains('sync.entities_failed', $attributes);
        $this->assertContains('sync.entities_skipped', $attributes);
        $this->assertContains('sync.entities_conflict', $attributes);
    }

    public function testSupportsPerformanceMetrics(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для performance метрик
        $this->assertContains('sync.throughput_eps', $attributes);
        $this->assertContains('sync.avg_entity_duration_ms', $attributes);
        $this->assertContains('sync.batch_size', $attributes);
    }

    public function testSupportsConflictResolution(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для разрешения конфликтов
        $this->assertContains('conflict.type', $attributes);
        $this->assertContains('conflict.resolution', $attributes);
        $this->assertContains('conflict.field', $attributes);
        $this->assertContains('conflict.bitrix_value', $attributes);
        $this->assertContains('conflict.external_value', $attributes);
    }

    public function testIsAttributeAllowedReturnsTrueForIntegrationAttributes(): void
    {
        $this->assertTrue($this->profile->isAttributeAllowed('sync.id'));
        $this->assertTrue($this->profile->isAttributeAllowed('sync.type'));
        $this->assertTrue($this->profile->isAttributeAllowed('entity.type'));
        $this->assertTrue($this->profile->isAttributeAllowed('conflict.type'));
        $this->assertTrue($this->profile->isAttributeAllowed('integration.system_name'));
    }

    public function testIsAttributeAllowedReturnsFalseForNonIntegrationAttributes(): void
    {
        // Атрибуты из других профилей
        $this->assertFalse($this->profile->isAttributeAllowed('app.id'));
        $this->assertFalse($this->profile->isAttributeAllowed('ui.surface'));
        $this->assertFalse($this->profile->isAttributeAllowed('migration.stage'));
    }

    public function testGetAttributeCountReturnsPositiveNumber(): void
    {
        $count = $this->profile->getAttributeCount();

        $this->assertGreaterThan(0, $count);
        // IntegrationProfile должен иметь минимум 30 атрибутов
        $this->assertGreaterThanOrEqual(30, $count);
    }
}
