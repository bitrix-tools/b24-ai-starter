<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Profiles;

use App\Service\Telemetry\Profiles\UIProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для UIProfile.
 *
 * Проверяем:
 * - Корректный список атрибутов для UI-Centric приложений
 * - Соответствие semantic conventions
 * - Все группы атрибутов присутствуют (UI, Interaction, Session, Action, External, API)
 * - Поддержка платежных систем и SMS-провайдеров
 */
class UIProfileTest extends TestCase
{
    private UIProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new UIProfile();
    }

    public function testGetNameReturnsUi(): void
    {
        $this->assertSame('ui', $this->profile->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $description = $this->profile->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('UI', $description);
    }

    public function testGetAllowedAttributesReturnsExpectedAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        // Проверяем наличие ключевых атрибутов из каждой группы
        $expectedAttributes = [
            // UI Navigation
            'ui.surface',
            'ui.placement_code',
            'screen.name',
            'screen.section',

            // User Interaction
            'interaction.type',
            'button.name',
            'button.action',
            'form.id',
            'form.type',
            'widget.placement',

            // Session
            'session.id',
            'session.start_time',
            'session.duration_ms',
            'user.id',
            'user.role',

            // Action Execution
            'action.name',
            'action.type',
            'action.status',
            'action.duration_ms',
            'action.sync',

            // External System
            'external.system_name',
            'external.operation_type',
            'external.operation_id',
            'external.status',
            'external.amount',
            'external.currency',
            'external.recipient',

            // API Calls
            'api.provider',
            'api.method',
            'api.endpoint',
            'api.http_status',
            'api.duration_ms',
            'api.retry_count',
        ];

        foreach ($expectedAttributes as $expectedAttribute) {
            $this->assertContains(
                $expectedAttribute,
                $attributes,
                sprintf('Attribute "%s" is missing in UIProfile', $expectedAttribute),
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

    public function testUiNavigationAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $uiAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'ui.'));
        $screenAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'screen.'));

        $this->assertNotEmpty($uiAttributes, 'UI attributes group is missing');
        $this->assertNotEmpty($screenAttributes, 'Screen attributes group is missing');
        $this->assertGreaterThanOrEqual(4, count($uiAttributes) + count($screenAttributes));
    }

    public function testUserInteractionAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $interactionAttributes = array_filter(
            $attributes,
            fn ($attr) => str_starts_with($attr, 'interaction.')
            || str_starts_with($attr, 'button.')
            || str_starts_with($attr, 'form.')
            || str_starts_with($attr, 'widget.'),
        );

        $this->assertNotEmpty($interactionAttributes, 'User interaction attributes group is missing');
        $this->assertGreaterThanOrEqual(6, count($interactionAttributes));
    }

    public function testSessionAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $sessionAttributes = array_filter(
            $attributes,
            fn ($attr) => str_starts_with($attr, 'session.') || str_starts_with($attr, 'user.'),
        );

        $this->assertNotEmpty($sessionAttributes, 'Session attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($sessionAttributes));
    }

    public function testActionExecutionAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $actionAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'action.'));

        $this->assertNotEmpty($actionAttributes, 'Action execution attributes group is missing');
        $this->assertGreaterThanOrEqual(5, count($actionAttributes));
    }

    public function testExternalSystemAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $externalAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'external.'));

        $this->assertNotEmpty($externalAttributes, 'External system attributes group is missing');
        $this->assertGreaterThanOrEqual(7, count($externalAttributes));
    }

    public function testApiCallsAttributesGroupExists(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        $apiAttributes = array_filter($attributes, fn ($attr) => str_starts_with($attr, 'api.'));

        $this->assertNotEmpty($apiAttributes, 'API calls attributes group is missing');
        $this->assertGreaterThanOrEqual(6, count($apiAttributes));
    }

    public function testSupportsPaymentSystemAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для платежных систем
        $this->assertContains('external.system_name', $attributes);
        $this->assertContains('external.operation_type', $attributes);
        $this->assertContains('external.amount', $attributes);
        $this->assertContains('external.currency', $attributes);
        $this->assertContains('external.status', $attributes);
    }

    public function testSupportsSmsProviderAttributes(): void
    {
        $attributes = $this->profile->getAllowedAttributes();

        // Атрибуты для SMS-провайдеров
        $this->assertContains('external.system_name', $attributes);
        $this->assertContains('external.operation_type', $attributes);
        $this->assertContains('external.recipient', $attributes);
        $this->assertContains('api.provider', $attributes);
        $this->assertContains('api.method', $attributes);
    }

    public function testIsAttributeAllowedReturnsTrueForUiAttributes(): void
    {
        $this->assertTrue($this->profile->isAttributeAllowed('ui.surface'));
        $this->assertTrue($this->profile->isAttributeAllowed('screen.name'));
        $this->assertTrue($this->profile->isAttributeAllowed('button.name'));
        $this->assertTrue($this->profile->isAttributeAllowed('action.name'));
        $this->assertTrue($this->profile->isAttributeAllowed('external.system_name'));
        $this->assertTrue($this->profile->isAttributeAllowed('api.provider'));
    }

    public function testIsAttributeAllowedReturnsFalseForNonUiAttributes(): void
    {
        // Атрибуты из lifecycle (должны быть в отдельном профиле)
        $this->assertFalse($this->profile->isAttributeAllowed('app.id'));
        $this->assertFalse($this->profile->isAttributeAllowed('portal.id'));
        $this->assertFalse($this->profile->isAttributeAllowed('lifecycle.event_type'));

        // Атрибуты из других профилей
        $this->assertFalse($this->profile->isAttributeAllowed('sync.type'));
        $this->assertFalse($this->profile->isAttributeAllowed('migration.stage'));
    }

    public function testGetAttributeCountReturnsPositiveNumber(): void
    {
        $count = $this->profile->getAttributeCount();

        $this->assertGreaterThan(0, $count);
        // UIProfile должен иметь минимум 30 атрибутов
        $this->assertGreaterThanOrEqual(30, $count);
    }
}
