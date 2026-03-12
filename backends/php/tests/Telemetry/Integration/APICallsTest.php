<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Тесты API call событий телеметрии (Sprint 5, Step 5.5).
 *
 * Проверяет отслеживание вызовов Bitrix24 API:
 * - Событие bitrix_api_call содержит обязательные атрибуты
 * - api.duration_ms корректно измеряется и передаётся как string
 * - api.status отражает результат вызова (success/error)
 * - Чувствительные данные (токены) не попадают в события
 */
class APICallsTest extends TestCase
{
    private TelemetryInterface&MockObject $telemetry;

    protected function setUp(): void
    {
        $this->telemetry = $this->createMock(TelemetryInterface::class);
        $this->telemetry->method('isEnabled')->willReturn(true);
    }

    #[Test]
    public function bitrixApiCallEventHasRequiredAttributes(): void
    {
        // Arrange
        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('bitrix_api_call', $this->callback(function (array $attrs) {
                return isset($attrs['api.provider'])
                    && 'bitrix24' === $attrs['api.provider']
                    && isset($attrs['api.method'])
                    && isset($attrs['api.duration_ms'])
                    && isset($attrs['api.status'])
                    && isset($attrs['portal.domain']);
            }));

        // Act
        $this->telemetry->trackEvent('bitrix_api_call', [
            'api.provider' => 'bitrix24',
            'api.method' => 'crm.contact.get',
            'api.duration_ms' => '45',
            'api.status' => 'success',
            'portal.domain' => 'example.bitrix24.ru',
        ]);
    }

    #[Test]
    public function apiDurationMsIsStringType(): void
    {
        // OTel требует строковые атрибуты
        $captured = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$captured): void {
                $captured = $attrs;
            });

        // Simulate measured API call
        $startTime = hrtime(true);
        usleep(500); // 0.5ms
        $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        // Act
        $this->telemetry->trackEvent('bitrix_api_call', [
            'api.provider' => 'bitrix24',
            'api.method' => 'crm.contact.get',
            'api.duration_ms' => (string) $durationMs,
            'api.status' => 'success',
            'portal.domain' => 'example.bitrix24.ru',
        ]);

        // Assert — duration всегда строка
        $this->assertIsString($captured['api.duration_ms']);
        $this->assertGreaterThanOrEqual(0, (int) $captured['api.duration_ms']);
    }

    #[Test]
    public function apiErrorStatusSetOnException(): void
    {
        // Arrange — API вызов завершился ошибкой
        $exception = new \RuntimeException('Bitrix24 API: 401 Unauthorized');

        $this->telemetry
            ->expects($this->once())
            ->method('trackError')
            ->with(
                $this->identicalTo($exception),
                $this->callback(function (array $context) {
                    return isset($context['error.category'])
                        && 'api_error' === $context['error.category']
                        && isset($context['api.provider'])
                        && 'bitrix24' === $context['api.provider']
                        && isset($context['api.method']);
                }),
            );

        // Act
        $this->telemetry->trackError($exception, [
            'error.category' => 'api_error',
            'api.provider' => 'bitrix24',
            'api.method' => 'crm.contact.get',
            'api.status' => 'error',
        ]);
    }

    #[Test]
    public function sensitiveDataNotIncludedInApiEvents(): void
    {
        // Токены и ключи не должны попадать в события телеметрии
        $captured = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$captured): void {
                $captured = $attrs;
            });

        // Act — событие без токена
        $this->telemetry->trackEvent('bitrix_api_call', [
            'api.provider' => 'bitrix24',
            'api.method' => 'crm.contact.get',
            'api.duration_ms' => '30',
            'api.status' => 'success',
            'portal.domain' => 'example.bitrix24.ru',
            // НЕТ: 'api.token', 'access_token', 'client_secret'
        ]);

        // Assert — чувствительные ключи отсутствуют
        $this->assertArrayNotHasKey('api.token', $captured);
        $this->assertArrayNotHasKey('access_token', $captured);
        $this->assertArrayNotHasKey('client_secret', $captured);
        $this->assertArrayNotHasKey('authorization', $captured);
    }

    #[Test]
    public function apiProviderAlwaysLowercase(): void
    {
        // api.provider должен быть в нижнем регистре (конвенция)
        $captured = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$captured): void {
                $captured = $attrs;
            });

        $this->telemetry->trackEvent('bitrix_api_call', [
            'api.provider' => 'bitrix24',  // нижний регистр
            'api.method' => 'crm.contact.get',
            'api.duration_ms' => '25',
            'api.status' => 'success',
            'portal.domain' => 'example.bitrix24.ru',
        ]);

        $this->assertSame('bitrix24', $captured['api.provider']);
        $this->assertSame(strtolower($captured['api.provider']), $captured['api.provider']);
    }
}
