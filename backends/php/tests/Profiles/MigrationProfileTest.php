<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Profiles;

use App\Service\Telemetry\Profiles\IntegrationProfile;
use App\Service\Telemetry\Profiles\MigrationProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для MigrationProfile.
 *
 * Проверяем:
 * - Наследование от IntegrationProfile
 * - Корректный список атрибутов для миграторов
 * - Все группы атрибутов присутствуют
 * - Поддержка RFC SLI метрик (coverage, liveness)
 * - Поддержка stages, batches, retry logic
 */
class MigrationProfileTest extends TestCase
{
    private MigrationProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new MigrationProfile();
    }

    public function testGetNameReturnsMigration(): void
    {
        $this->assertSame('migration', $this->profile->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $description = $this->profile->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('migrator', $description);
    }

    public function testExtendsIntegrationProfile(): void
    {
        $this->assertInstanceOf(IntegrationProfile::class, $this->profile);
    }

    public function testInheritsIntegrationProfileAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Проверяем наличие атрибутов из IntegrationProfile
        $integrationAttributes = [
            'sync.id',
            'sync.type',
            'entity.type',
            'conflict.type',
            'integration.system_name',
        ];

        foreach ($integrationAttributes as $attr) {
            $this->assertContains(
                $attr,
                $attributes,
                sprintf('Inherited attribute "%s" from IntegrationProfile is missing', $attr),
            );
        }
    }

    public function testGetAllowedAttributesReturnsExpectedMigrationAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        $expectedMigrationAttributes = [
            // Migration Process
            'migration.id',
            'migration.type',
            'migration.source_system',
            'migration.trigger',
            'migration.status',
            'migration.outcome',
            'migration.completion_percentage',
            'migration.is_complete',
            'migration.can_resume',
            'migration.resume_from_stage',

            // Versioning
            'migration.attempt_number',
            'migration.retry_of',
            'migration.parent_migration_id',

            // Stages
            'stage.id',
            'stage.name',
            'stage.index',
            'stage.status',
            'stage.duration_ms',
            'stage.start_time',
            'stage.end_time',
            'stage.items_total',
            'stage.items_processed',
            'stage.items_failed',
            'stage.throughput_ops',

            // Batches
            'batch.id',
            'batch.index',
            'batch.size',
            'batch.offset',
            'batch.status',
            'batch.duration_ms',

            // Retry Logic
            'retry.attempt',
            'retry.max_attempts',
            'retry.reason',
            'retry.outcome',
            'retry.next_attempt_delay_ms',
            'retry.strategy',

            // Items
            'items.type',
            'items.total_planned',
            'items.total_processed',
            'items.successful',
            'items.failed',
            'items.skipped',
            'items.ids',

            // Coverage/Quality
            'objects.type',
            'objects.detected',
            'objects.planned',
            'objects.imported',
            'objects.failed',
            'objects.skipped',
            'coverage.percentage',
            'coverage.status',

            // Liveness
            'process.last_activity_timestamp',
            'process.last_stage_completed',
            'process.idle_duration_ms',
            'process.is_stale',
            'process.stale_threshold_ms',
            'process.expected_completion_time',
            'process.heartbeat_interval_ms',
            'process.last_heartbeat',

            // Performance
            'process.start_timestamp',
            'process.end_timestamp',
            'process.duration_total_ms',
            'process.duration_planned_ms',
            'process.throughput_ops',
            'process.throughput_bytes',
            'process.avg_item_duration_ms',

            // Error Classification
            'error.source',
            'error.service',
            'error.is_retryable',
            'error.category',
            'error.http_status',
            'error.rate_limit',
            'error.affects_completion',
            'error.data_loss_risk',

            // Failure Reasons
            'migration.failure_reason',
        ];

        foreach ($expectedMigrationAttributes as $expectedAttribute) {
            $this->assertContains(
                $expectedAttribute,
                $attributes,
                sprintf('Attribute "%s" is missing in MigrationProfile', $expectedAttribute),
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

    public function testMigrationProcessAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $migrationAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'migration.'));

        $this->assertNotEmpty($migrationAttributes, 'Migration process attributes group is missing');
        $this->assertGreaterThanOrEqual(10, count($migrationAttributes));
    }

    public function testStageAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $stageAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'stage.'));

        $this->assertNotEmpty($stageAttributes, 'Stage attributes group is missing');
        $this->assertGreaterThanOrEqual(10, count($stageAttributes));
    }

    public function testBatchAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $batchAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'batch.'));

        $this->assertNotEmpty($batchAttributes, 'Batch attributes group is missing');
        $this->assertGreaterThanOrEqual(6, count($batchAttributes));
    }

    public function testRetryLogicAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $retryAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'retry.'));

        $this->assertNotEmpty($retryAttributes, 'Retry logic attributes group is missing');
        $this->assertGreaterThanOrEqual(6, count($retryAttributes));
    }

    public function testItemsAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $itemsAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'items.'));

        $this->assertNotEmpty($itemsAttributes, 'Items attributes group is missing');
        $this->assertGreaterThanOrEqual(6, count($itemsAttributes));
    }

    public function testCoverageQualityAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $coverageAttributes = array_filter(
            $attributes,
            fn ($attr) => str_starts_with($attr, 'objects.') || str_starts_with($attr, 'coverage.'),
        );

        $this->assertNotEmpty($coverageAttributes, 'Coverage/Quality attributes group is missing');
        $this->assertGreaterThanOrEqual(8, count($coverageAttributes));
    }

    public function testLivenessAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $livenessAttributes = array_filter(
            $attributes,
            fn ($attr) => str_starts_with($attr, 'process.')
            && (str_contains($attr, 'activity') || str_contains($attr, 'stale')
             || str_contains($attr, 'heartbeat') || str_contains($attr, 'idle')),
        );

        $this->assertNotEmpty($livenessAttributes, 'Liveness attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($livenessAttributes));
    }

    public function testPerformanceAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $performanceAttributes = array_filter(
            $attributes,
            fn ($attr) => str_starts_with($attr, 'process.')
            && (str_contains($attr, 'timestamp') || str_contains($attr, 'duration')
             || str_contains($attr, 'throughput') || str_contains($attr, 'avg')),
        );

        $this->assertNotEmpty($performanceAttributes, 'Performance attributes group is missing');
        $this->assertGreaterThanOrEqual(7, count($performanceAttributes));
    }

    public function testErrorClassificationAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $errorAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'error.'));

        $this->assertNotEmpty($errorAttributes, 'Error classification attributes group is missing');
        $this->assertGreaterThanOrEqual(8, count($errorAttributes));
    }

    public function testSupportsRfcSliCoverageMetrics(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // RFC SLI-4: Coverage metrics
        $this->assertContains('objects.detected', $attributes);
        $this->assertContains('objects.imported', $attributes);
        $this->assertContains('coverage.percentage', $attributes);
        $this->assertContains('coverage.status', $attributes);
    }

    public function testSupportsRfcSliLivenessMetrics(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // RFC SLI-2: Liveness metrics
        $this->assertContains('process.last_activity_timestamp', $attributes);
        $this->assertContains('process.is_stale', $attributes);
        $this->assertContains('process.idle_duration_ms', $attributes);
        $this->assertContains('process.heartbeat_interval_ms', $attributes);
    }

    public function testSupportsStageBasedProcessing(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для многоэтапной обработки
        $this->assertContains('stage.id', $attributes);
        $this->assertContains('stage.name', $attributes);
        $this->assertContains('stage.status', $attributes);
        $this->assertContains('stage.items_total', $attributes);
        $this->assertContains('stage.items_processed', $attributes);
    }

    public function testSupportsBatchProcessing(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для пакетной обработки
        $this->assertContains('batch.id', $attributes);
        $this->assertContains('batch.index', $attributes);
        $this->assertContains('batch.size', $attributes);
        $this->assertContains('batch.offset', $attributes);
    }

    public function testSupportsRetryMechanism(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для retry логики
        $this->assertContains('retry.attempt', $attributes);
        $this->assertContains('retry.max_attempts', $attributes);
        $this->assertContains('retry.strategy', $attributes);
        $this->assertContains('migration.attempt_number', $attributes);
        $this->assertContains('migration.retry_of', $attributes);
    }

    public function testSupportsVersioningForRetry(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Версионность для retry
        $this->assertContains('migration.attempt_number', $attributes);
        $this->assertContains('migration.retry_of', $attributes);
        $this->assertContains('migration.parent_migration_id', $attributes);
    }

    public function testIsAttributeAllowedReturnsTrueForMigrationAttributes(): void
    {
        $this->assertTrue($this->profile->isAttributeAllowed('migration.id'));
        $this->assertTrue($this->profile->isAttributeAllowed('stage.name'));
        $this->assertTrue($this->profile->isAttributeAllowed('batch.size'));
        $this->assertTrue($this->profile->isAttributeAllowed('coverage.percentage'));
        $this->assertTrue($this->profile->isAttributeAllowed('process.is_stale'));
    }

    public function testIsAttributeAllowedReturnsTrueForInheritedIntegrationAttributes(): void
    {
        $this->assertTrue($this->profile->isAttributeAllowed('sync.id'));
        $this->assertTrue($this->profile->isAttributeAllowed('entity.type'));
        $this->assertTrue($this->profile->isAttributeAllowed('conflict.type'));
    }

    public function testIsAttributeAllowedReturnsFalseForNonMigrationAttributes(): void
    {
        // Атрибуты из других профилей (не из Integration или Migration)
        $this->assertFalse($this->profile->isAttributeAllowed('app.id'));
        $this->assertFalse($this->profile->isAttributeAllowed('ui.surface'));
        $this->assertFalse($this->profile->isAttributeAllowed('unknown.attribute'));
    }

    public function testGetAttributeCountIncludesInheritedAttributes(): void
    {
        $count = $this->profile->getAttributeCount();

        $this->assertGreaterThan(0, $count);
        // MigrationProfile наследует ~34 атрибута из IntegrationProfile + добавляет ~70+ своих
        $this->assertGreaterThanOrEqual(100, $count);
    }

    public function testHasMoreAttributesThanIntegrationProfile(): void
    {
        $integrationProfile = new IntegrationProfile();

        $migrationCount = $this->profile->getAttributeCount();
        $integrationCount = $integrationProfile->getAttributeCount();

        $this->assertGreaterThan(
            $integrationCount,
            $migrationCount,
            'MigrationProfile should have more attributes than IntegrationProfile',
        );
    }
}
