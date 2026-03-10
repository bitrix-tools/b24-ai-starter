<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Profiles;

use App\Service\Telemetry\Profiles\BaseProfile;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для BaseProfile.
 *
 * Проверяем:
 * - Валидацию имён атрибутов (semantic conventions)
 * - Кэширование списка атрибутов
 * - Проверку принадлежности атрибута профилю
 * - Подсчёт количества атрибутов
 */
class BaseProfileTest extends TestCase
{
    /**
     * Создаёт тестовый профиль с заданными атрибутами.
     *
     * @param array<string> $attributes
     */
    private function createTestProfile(array $attributes, string $name = 'test', string $description = 'Test profile'): BaseProfile
    {
        return new class ($attributes, $name, $description) extends BaseProfile {
            /**
             * @param array<string> $attributes
             */
            public function __construct(
                private array $attributes,
                string $name,
                string $description,
            ) {
                $this->name = $name;
                $this->description = $description;
            }

            protected function defineAttributes(): array
            {
                return $this->attributes;
            }
        };
    }

    public function testGetAllowedAttributesReturnsDefinedAttributes(): void
    {
        $attributes = ['app.id', 'app.version', 'portal.id'];
        $profile = $this->createTestProfile($attributes);

        $this->assertSame($attributes, $profile->getAllowedAttributes());
    }

    public function testGetAllowedAttributesCachesResult(): void
    {
        $attributes = ['app.id', 'app.version'];
        $profile = $this->createTestProfile($attributes);

        $result1 = $profile->getAllowedAttributes();
        $result2 = $profile->getAllowedAttributes();

        // Должны вернуться те же самые массивы (cache)
        $this->assertSame($result1, $result2);
    }

    public function testGetNameReturnsProfileName(): void
    {
        $profile = $this->createTestProfile(['app.id'], 'lifecycle', 'Lifecycle profile');

        $this->assertSame('lifecycle', $profile->getName());
    }

    public function testGetDescriptionReturnsProfileDescription(): void
    {
        $profile = $this->createTestProfile(['app.id'], 'test', 'Test description for profile');

        $this->assertSame('Test description for profile', $profile->getDescription());
    }

    public function testIsAttributeAllowedReturnsTrueForAllowedAttribute(): void
    {
        $profile = $this->createTestProfile(['app.id', 'app.version', 'portal.id']);

        $this->assertTrue($profile->isAttributeAllowed('app.id'));
        $this->assertTrue($profile->isAttributeAllowed('app.version'));
        $this->assertTrue($profile->isAttributeAllowed('portal.id'));
    }

    public function testIsAttributeAllowedReturnsFalseForDisallowedAttribute(): void
    {
        $profile = $this->createTestProfile(['app.id', 'app.version']);

        $this->assertFalse($profile->isAttributeAllowed('portal.id'));
        $this->assertFalse($profile->isAttributeAllowed('sync.type'));
        $this->assertFalse($profile->isAttributeAllowed('unknown.attribute'));
    }

    public function testGetAttributeCountReturnsCorrectNumber(): void
    {
        $profile = $this->createTestProfile(['app.id', 'app.version', 'portal.id']);

        $this->assertSame(3, $profile->getAttributeCount());
    }

    public function testGetAttributeCountReturnsZeroForEmptyProfile(): void
    {
        $profile = $this->createTestProfile([]);

        $this->assertSame(0, $profile->getAttributeCount());
    }

    /**
     * Валидные имена атрибутов по semantic conventions.
     */
    public function testValidAttributeNamesAreAccepted(): void
    {
        $validNames = [
            'app.id',
            'app.version',
            'lifecycle.event_type',
            'external.system_name',
            'api.http_status',
            'session.duration_ms',
            'user.id',
            'sync.entities_total',
        ];

        $profile = $this->createTestProfile($validNames);

        $this->assertSame($validNames, $profile->getAllowedAttributes());
    }

    /**
     * Невалидные имена атрибутов должны вызывать исключение.
     */
    public function testInvalidAttributeNamesThrowException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not follow semantic conventions');

        // Uppercase не допускается
        $profile = $this->createTestProfile(['App.Id', 'portal.id']);
        $profile->getAllowedAttributes(); // Валидация происходит при первом вызове
    }

    public function testAttributeNameWithSpacesThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $profile = $this->createTestProfile(['app id', 'portal.id']);
        $profile->getAllowedAttributes();
    }

    public function testAttributeNameWithHyphenThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $profile = $this->createTestProfile(['app-id', 'portal.id']);
        $profile->getAllowedAttributes();
    }

    public function testEmptyAttributeNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a non-empty string');

        $profile = $this->createTestProfile(['app.id', '', 'portal.id']);
        $profile->getAllowedAttributes();
    }

    public function testAttributeNameStartingWithDotThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $profile = $this->createTestProfile(['.app.id', 'portal.id']);
        $profile->getAllowedAttributes();
    }

    public function testAttributeNameEndingWithDotThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $profile = $this->createTestProfile(['app.id.', 'portal.id']);
        $profile->getAllowedAttributes();
    }

    public function testAttributeNameWithSpecialCharactersThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $profile = $this->createTestProfile(['app@id', 'portal.id']);
        $profile->getAllowedAttributes();
    }
}
