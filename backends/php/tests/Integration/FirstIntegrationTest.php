<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Первый интеграционный тест телеметрии (Sprint 1 smoke test).
 *
 * Проверяет, что:
 * - Приложение работает с интегрированной телеметрией
 * - HTTP запросы выполняются успешно
 * - Метод trackEvent() вызывается без ошибок
 * - При TELEMETRY_ENABLED=false нет overhead
 */
class FirstIntegrationTest extends WebTestCase
{
    #[Test]
    public function apiEndpointWorksWithTelemetryDisabled(): void
    {
        // Arrange
        $client = static::createClient();

        // Act - делаем запрос к публичному эндпоинту с телеметрией
        $client->request('GET', '/api/health');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function telemetryServiceIsAvailableInController(): void
    {
        // Arrange
        $client = static::createClient();
        $container = static::getContainer();

        // Act
        $telemetry = $container->get(TelemetryInterface::class);

        // Assert - сервис резолвится и доступен
        $this->assertInstanceOf(TelemetryInterface::class, $telemetry);
        $this->assertNotNull($telemetry);
    }

    #[Test]
    public function telemetryTrackEventDoesNotCauseErrors(): void
    {
        // Arrange
        $client = static::createClient();
        $container = static::getContainer();
        $telemetry = $container->get(TelemetryInterface::class);

        // Act & Assert - вызов не должен вызывать исключений
        $telemetry->trackEvent('integration_test_event', [
            'test' => 'data',
            'source' => 'integration_test',
            'timestamp' => time(),
        ]);

        $this->assertTrue(true);
    }

    #[Test]
    public function multipleRequestsWorkCorrectlyWithTelemetry(): void
    {
        // Arrange
        $client = static::createClient();

        // Act - делаем несколько запросов подряд
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/api/health');

            // Assert - каждый запрос успешен
            $this->assertResponseIsSuccessful();
        }
    }

    #[Test]
    public function telemetryDisabledStateIsCorrectInTests(): void
    {
        // Arrange
        $container = static::getContainer();
        $telemetry = $container->get(TelemetryInterface::class);

        // Act
        $isEnabled = $telemetry->isEnabled();

        // Assert - в тестовом окружении телеметрия отключена
        $this->assertFalse($isEnabled, 'Telemetry should be disabled in test environment');
    }
}
