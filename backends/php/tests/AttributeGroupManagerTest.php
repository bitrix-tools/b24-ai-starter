<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\AttributeGroupManager;
use App\Service\Telemetry\Profiles\IntegrationProfile;
use App\Service\Telemetry\Profiles\LifecycleProfile;
use App\Service\Telemetry\Profiles\MigrationProfile;
use App\Service\Telemetry\Profiles\ProfileInterface;
use App\Service\Telemetry\Profiles\UIProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для AttributeGroupManager.
 *
 * Проверяем:
 * - Композицию нескольких профилей
 * - Фильтрацию атрибутов
 * - Дедупликацию при пересечении профилей
 * - Exclusion patterns с wildcard (*) поддержкой
 * - Валидацию входных данных
 */
class AttributeGroupManagerTest extends TestCase
{
    public function testConstructorRequiresAtLeastOneProfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one profile must be provided');

        new AttributeGroupManager([]);
    }

    public function testConstructorValidatesProfileType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile must implement ProfileInterface');

        /* @phpstan-ignore-next-line */
        new AttributeGroupManager(['not a profile']);
    }

    public function testConstructorValidatesExcludePatterns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exclude pattern must be a non-empty string');

        new AttributeGroupManager([new LifecycleProfile()], ['valid', 123]);
    }

    public function testConstructorWithEmptyExcludePatternThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttributeGroupManager([new LifecycleProfile()], ['']);
    }

    public function testGetAllowedAttributesFromSingleProfile(): void
    {
        $profile = new LifecycleProfile();
        $manager = new AttributeGroupManager([$profile]);

        $attributes = $manager->getAllowedAttributes();

        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);
        $this->assertContains('app.id', $attributes);
        $this->assertContains('lifecycle.event_type', $attributes);
    }

    public function testCompositionOfMultipleProfiles(): void
    {
        $lifecycleProfile = new LifecycleProfile();
        $uiProfile = new UIProfile();

        $manager = new AttributeGroupManager([$lifecycleProfile, $uiProfile]);

        $attributes = $manager->getAllowedAttributes();

        // Проверяем атрибуты из LifecycleProfile
        $this->assertContains('app.id', $attributes);
        $this->assertContains('lifecycle.event_type', $attributes);

        // Проверяем атрибуты из UIProfile
        $this->assertContains('ui.surface', $attributes);
        $this->assertContains('action.name', $attributes);
    }

    public function testDeduplicationOfAttributes(): void
    {
        // Создаём профили с пересекающимися атрибутами
        $profile1 = $this->createMockProfile(['app.id', 'user.id', 'action.name']);
        $profile2 = $this->createMockProfile(['user.id', 'action.name', 'session.id']);

        $manager = new AttributeGroupManager([$profile1, $profile2]);

        $attributes = $manager->getAllowedAttributes();

        // Должно быть 4 уникальных атрибута
        $this->assertCount(4, $attributes);
        $this->assertContains('app.id', $attributes);
        $this->assertContains('user.id', $attributes);
        $this->assertContains('action.name', $attributes);
        $this->assertContains('session.id', $attributes);

        // Проверяем, что нет дубликатов
        $this->assertEquals($attributes, array_unique($attributes));
    }

    public function testExclusionPatternsExactMatch(): void
    {
        $profile = $this->createMockProfile(['app.id', 'sync.id', 'sync.type', 'user.id']);

        $manager = new AttributeGroupManager([$profile], ['sync.id']);

        $attributes = $manager->getAllowedAttributes();

        $this->assertContains('app.id', $attributes);
        $this->assertContains('sync.type', $attributes);
        $this->assertContains('user.id', $attributes);
        $this->assertNotContains('sync.id', $attributes);
    }

    public function testExclusionPatternsWithWildcard(): void
    {
        $profile = $this->createMockProfile([
            'app.id',
            'sync.id',
            'sync.type',
            'sync.status',
            'user.id',
            'migration.id',
            'migration.type',
        ]);

        $manager = new AttributeGroupManager([$profile], ['sync.*']);

        $attributes = $manager->getAllowedAttributes();

        $this->assertContains('app.id', $attributes);
        $this->assertContains('user.id', $attributes);
        $this->assertContains('migration.id', $attributes);
        $this->assertContains('migration.type', $attributes);

        // Все sync.* должны быть исключены
        $this->assertNotContains('sync.id', $attributes);
        $this->assertNotContains('sync.type', $attributes);
        $this->assertNotContains('sync.status', $attributes);
    }

    public function testExclusionPatternsWithMultipleWildcards(): void
    {
        $profile = $this->createMockProfile([
            'app.id',
            'sync.id',
            'sync.type',
            'migration.id',
            'migration.stage',
            'user.id',
        ]);

        $manager = new AttributeGroupManager([$profile], ['sync.*', 'migration.*']);

        $attributes = $manager->getAllowedAttributes();

        $this->assertContains('app.id', $attributes);
        $this->assertContains('user.id', $attributes);

        // Все sync.* и migration.* должны быть исключены
        $this->assertNotContains('sync.id', $attributes);
        $this->assertNotContains('sync.type', $attributes);
        $this->assertNotContains('migration.id', $attributes);
        $this->assertNotContains('migration.stage', $attributes);
    }

    public function testFilterAttributesKeepsOnlyAllowed(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id', 'action.name']);
        $manager = new AttributeGroupManager([$profile]);

        $eventAttributes = [
            'app.id' => 'test-app',
            'user.id' => '123',
            'action.name' => 'button_click',
            'sync.id' => 'should-be-filtered', // не разрешён
            'unknown.attr' => 'should-be-filtered', // не разрешён
        ];

        $filtered = $manager->filterAttributes($eventAttributes);

        $this->assertCount(3, $filtered);
        $this->assertArrayHasKey('app.id', $filtered);
        $this->assertArrayHasKey('user.id', $filtered);
        $this->assertArrayHasKey('action.name', $filtered);
        $this->assertArrayNotHasKey('sync.id', $filtered);
        $this->assertArrayNotHasKey('unknown.attr', $filtered);
    }

    public function testFilterAttributesPreservesValues(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id']);
        $manager = new AttributeGroupManager([$profile]);

        $eventAttributes = [
            'app.id' => 'my-app-123',
            'user.id' => '456',
            'unknown' => 'value',
        ];

        $filtered = $manager->filterAttributes($eventAttributes);

        $this->assertSame('my-app-123', $filtered['app.id']);
        $this->assertSame('456', $filtered['user.id']);
    }

    public function testIsAttributeAllowedReturnsTrueForAllowedAttribute(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id']);
        $manager = new AttributeGroupManager([$profile]);

        $this->assertTrue($manager->isAttributeAllowed('app.id'));
        $this->assertTrue($manager->isAttributeAllowed('user.id'));
    }

    public function testIsAttributeAllowedReturnsFalseForDisallowedAttribute(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id']);
        $manager = new AttributeGroupManager([$profile]);

        $this->assertFalse($manager->isAttributeAllowed('sync.id'));
        $this->assertFalse($manager->isAttributeAllowed('unknown.attr'));
    }

    public function testGetFilteredOutAttributesReturnsOnlyFiltered(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id']);
        $manager = new AttributeGroupManager([$profile]);

        $eventAttributes = [
            'app.id' => 'test',
            'user.id' => '123',
            'sync.id' => 'filtered',
            'unknown.attr' => 'filtered',
        ];

        $filteredOut = $manager->getFilteredOutAttributes($eventAttributes);

        $this->assertCount(2, $filteredOut);
        $this->assertContains('sync.id', $filteredOut);
        $this->assertContains('unknown.attr', $filteredOut);
        $this->assertNotContains('app.id', $filteredOut);
        $this->assertNotContains('user.id', $filteredOut);
    }

    public function testGetProfilesReturnsActiveProfiles(): void
    {
        $profile1 = new LifecycleProfile();
        $profile2 = new UIProfile();

        $manager = new AttributeGroupManager([$profile1, $profile2]);

        $profiles = $manager->getProfiles();

        $this->assertCount(2, $profiles);
        $this->assertSame($profile1, $profiles[0]);
        $this->assertSame($profile2, $profiles[1]);
    }

    public function testGetExcludePatternsReturnsPatterns(): void
    {
        $profile = new LifecycleProfile();
        $excludePatterns = ['sync.*', 'migration.*'];

        $manager = new AttributeGroupManager([$profile], $excludePatterns);

        $this->assertSame($excludePatterns, $manager->getExcludePatterns());
    }

    public function testGetProfileCountReturnsCorrectCount(): void
    {
        $manager = new AttributeGroupManager([
            new LifecycleProfile(),
            new UIProfile(),
            new IntegrationProfile(),
        ]);

        $this->assertSame(3, $manager->getProfileCount());
    }

    public function testGetAllowedAttributeCountReturnsCorrectCount(): void
    {
        $profile = $this->createMockProfile(['app.id', 'user.id', 'action.name']);
        $manager = new AttributeGroupManager([$profile]);

        $this->assertSame(3, $manager->getAllowedAttributeCount());
    }

    public function testGetAllowedAttributeCountWithExclusionPatterns(): void
    {
        $profile = $this->createMockProfile(['app.id', 'sync.id', 'sync.type', 'user.id']);
        $manager = new AttributeGroupManager([$profile], ['sync.*']);

        // sync.id и sync.type должны быть исключены
        $this->assertSame(2, $manager->getAllowedAttributeCount());
    }

    public function testCachingOfAllowedAttributes(): void
    {
        $profile = new LifecycleProfile();
        $manager = new AttributeGroupManager([$profile]);

        $attributes1 = $manager->getAllowedAttributes();
        $attributes2 = $manager->getAllowedAttributes();

        // Должны вернуться те же самые массивы (кэш)
        $this->assertSame($attributes1, $attributes2);
    }

    public function testRealWorldScenarioSimpleUiProfile(): void
    {
        // Профиль simple-ui: Lifecycle + UI
        $manager = new AttributeGroupManager([
            new LifecycleProfile(),
            new UIProfile(),
        ]);

        $eventAttributes = [
            // Lifecycle атрибуты (разрешены)
            'app.id' => 'my-app',
            'portal.id' => 'portal123',
            'lifecycle.event_type' => 'install',

            // UI атрибуты (разрешены)
            'ui.surface' => 'placement',
            'button.name' => 'save',
            'action.name' => 'save_data',

            // Атрибуты из других профилей (должны быть отфильтрованы)
            'sync.id' => 'should-be-filtered',
            'migration.id' => 'should-be-filtered',
        ];

        $filtered = $manager->filterAttributes($eventAttributes);

        // Lifecycle и UI атрибуты присутствуют
        $this->assertArrayHasKey('app.id', $filtered);
        $this->assertArrayHasKey('portal.id', $filtered);
        $this->assertArrayHasKey('lifecycle.event_type', $filtered);
        $this->assertArrayHasKey('ui.surface', $filtered);
        $this->assertArrayHasKey('button.name', $filtered);
        $this->assertArrayHasKey('action.name', $filtered);

        // Атрибуты из IntegrationProfile и MigrationProfile отфильтрованы
        $this->assertArrayNotHasKey('sync.id', $filtered);
        $this->assertArrayNotHasKey('migration.id', $filtered);
    }

    public function testRealWorldScenarioWithExclusions(): void
    {
        // Integration профиль, но исключаем initial_sync.*
        $manager = new AttributeGroupManager(
            [new LifecycleProfile(), new IntegrationProfile()],
            ['initial_sync.*'],
        );

        $eventAttributes = [
            'app.id' => 'app',
            'sync.id' => 'sync123',
            'initial_sync.total_entities' => 'should-be-filtered',
            'initial_sync.completed_entities' => 'should-be-filtered',
        ];

        $filtered = $manager->filterAttributes($eventAttributes);

        $this->assertArrayHasKey('app.id', $filtered);
        $this->assertArrayHasKey('sync.id', $filtered);
        $this->assertArrayNotHasKey('initial_sync.total_entities', $filtered);
        $this->assertArrayNotHasKey('initial_sync.completed_entities', $filtered);
    }

    /**
     * Вспомогательный метод для создания mock профиля с заданными атрибутами.
     *
     * @param array<string> $attributes
     */
    private function createMockProfile(array $attributes): ProfileInterface
    {
        $profile = $this->createMock(ProfileInterface::class);
        $profile->method('getAllowedAttributes')->willReturn($attributes);
        $profile->method('getName')->willReturn('mock_profile');
        $profile->method('getDescription')->willReturn('Mock profile for testing');

        return $profile;
    }
}
