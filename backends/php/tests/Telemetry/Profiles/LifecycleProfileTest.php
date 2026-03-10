<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Profiles;

use App\Service\Telemetry\Profiles\LifecycleProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для LifecycleProfile.
 *
 * Проверяем:
 * - Корректный список атрибутов
 * - Соответствие semantic conventions
 * - Имя и описание профиля
 * - Все группы атрибутов присутствуют (app, portal, lifecycle, registration, event_handler, placement, automation)
 */
class LifecycleProfileTest extends TestCase
{
    private LifecycleProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new LifecycleProfile();
    }

    public function testGetNameReturnsLifecycle(): void
    {
        $this->assertSame('lifecycle', $this->profile->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $description = $this->profile->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('Lifecycle', $description);
    }

    public function testGetAllowedAttributesReturnsExpectedAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Проверяем, что вернулся массив
        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        // Проверяем наличие ключевых атрибутов из каждой группы
        $expectedAttributes = [
            // App
            'app.id',
            'app.version',
            'app.previous_version',

            // Portal
            'portal.id',
            'portal.member_id',
            'portal.domain',
            'portal.region',
            'portal.plan',

            // Lifecycle
            'lifecycle.event_type',
            'lifecycle.status',
            'lifecycle.trigger',

            // Registration
            'registration.type',
            'registration.scope',
            'registration.count',

            // Event Handler
            'event_handler.code',
            'event_handler.status',
            'event_handler.error_reason',

            // Placement
            'placement.code',
            'placement.title',
            'placement.url',

            // Automation
            'automation.type',
            'automation.code',
            'automation.scope',
        ];

        foreach ($expectedAttributes as $expectedAttribute) {
            $this->assertContains(
                $expectedAttribute,
                $attributes,
                sprintf('Attribute "%s" is missing in LifecycleProfile', $expectedAttribute),
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

    public function testAppAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $appAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'app.'));

        $this->assertNotEmpty($appAttributes, 'App attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($appAttributes), 'App attributes group should have at least 3 attributes');
    }

    public function testPortalAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $portalAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'portal.'));

        $this->assertNotEmpty($portalAttributes, 'Portal attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($portalAttributes), 'Portal attributes group should have at least 5 attributes');
    }

    public function testLifecycleAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $lifecycleAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'lifecycle.'));

        $this->assertNotEmpty($lifecycleAttributes, 'Lifecycle attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($lifecycleAttributes), 'Lifecycle attributes group should have at least 3 attributes');
    }

    public function testRegistrationAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $registrationAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'registration.'));

        $this->assertNotEmpty($registrationAttributes, 'Registration attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($registrationAttributes));
    }

    public function testEventHandlerAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $eventHandlerAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'event_handler.'));

        $this->assertNotEmpty($eventHandlerAttributes, 'Event handler attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($eventHandlerAttributes));
    }

    public function testPlacementAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $placementAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'placement.'));

        $this->assertNotEmpty($placementAttributes, 'Placement attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($placementAttributes));
    }

    public function testAutomationAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $automationAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'automation.'));

        $this->assertNotEmpty($automationAttributes, 'Automation attributes group is missing');
        $this->assertGreaterThanOrEqual(3, count($automationAttributes));
    }

    public function testIsAttributeAllowedReturnsTrueForLifecycleAttributes(): void
    {
        $this->assertTrue($this->profile->isAttributeAllowed('app.id'));
        $this->assertTrue($this->profile->isAttributeAllowed('portal.id'));
        $this->assertTrue($this->profile->isAttributeAllowed('lifecycle.event_type'));
        $this->assertTrue($this->profile->isAttributeAllowed('registration.type'));
    }

    public function testIsAttributeAllowedReturnsFalseForNonLifecycleAttributes(): void
    {
        // Атрибуты из других профилей (UI, Integration, Migration)
        $this->assertFalse($this->profile->isAttributeAllowed('ui.surface'));
        $this->assertFalse($this->profile->isAttributeAllowed('sync.type'));
        $this->assertFalse($this->profile->isAttributeAllowed('migration.stage'));
        $this->assertFalse($this->profile->isAttributeAllowed('unknown.attribute'));
    }

    public function testGetAttributeCountReturnsPositiveNumber(): void
    {
        $count = $this->profile->getAttributeCount();

        $this->assertGreaterThan(0, $count);
        // LifecycleProfile должен иметь минимум 20 атрибутов (все группы)
        $this->assertGreaterThanOrEqual(20, $count);
    }
}
