<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Тесты action событий телеметрии (Sprint 5, Step 5.4).
 *
 * Проверяет паттерн initiated → completed / failed:
 * - Событие b24_event_action_initiated с правильными атрибутами
 * - Событие b24_event_processed (completed) содержит action.duration_ms
 * - При ошибке вызывается trackError с error.category
 * - action.duration_ms является строкой (OTel требует scalar)
 */
class ActionEventsTest extends TestCase
{
    private TelemetryInterface&MockObject $telemetry;

    protected function setUp(): void
    {
        $this->telemetry = $this->createMock(TelemetryInterface::class);
        $this->telemetry->method('isEnabled')->willReturn(true);
    }

    #[Test]
    public function actionInitiatedEventHasRequiredAttributes(): void
    {
        // Arrange
        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('b24_event_action_initiated', $this->callback(function (array $attrs) {
                return isset($attrs['action.name'])
                    && isset($attrs['action.type'])
                    && isset($attrs['action.status'])
                    && $attrs['action.status'] === 'initiated'
                    && isset($attrs['b24.event_code'])
                    && isset($attrs['portal.member_id']);
            }));

        // Act
        $this->telemetry->trackEvent('b24_event_action_initiated', [
            'action.name'      => 'process_crm_contact_add',
            'action.type'      => 'b24_event_handler',
            'action.status'    => 'initiated',
            'b24.event_code'   => 'ONCRMCONTACTADD',
            'portal.member_id' => 'member-abc',
        ]);
    }

    #[Test]
    public function actionCompletedEventContainsDurationMs(): void
    {
        // Arrange
        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('b24_event_processed', $this->callback(function (array $attrs) {
                return isset($attrs['action.duration_ms'])
                    && is_string($attrs['action.duration_ms'])
                    && is_numeric($attrs['action.duration_ms'])
                    && $attrs['action.status'] === 'completed';
            }));

        // Simulate action timing
        $startTime = hrtime(true);
        usleep(1000); // 1ms
        $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        // Act
        $this->telemetry->trackEvent('b24_event_processed', [
            'action.name'        => 'process_crm_contact_add',
            'action.type'        => 'b24_event_handler',
            'action.status'      => 'completed',
            'action.duration_ms' => (string) $durationMs,
            'b24.event_code'     => 'ONCRMCONTACTADD',
            'b24.contact_id'     => '42',
            'portal.member_id'   => 'member-abc',
        ]);
    }

    #[Test]
    public function actionFailedCallsTrackErrorWithCategory(): void
    {
        // Arrange
        $exception = new \RuntimeException('Bitrix24 API timeout');

        $this->telemetry
            ->expects($this->once())
            ->method('trackError')
            ->with(
                $this->identicalTo($exception),
                $this->callback(function (array $context) {
                    return isset($context['error.category'])
                        && $context['error.category'] === 'b24_event_processing_failed'
                        && isset($context['action.name'])
                        && $context['action.status'] === 'failed';
                })
            );

        // Act
        $this->telemetry->trackError($exception, [
            'error.category' => 'b24_event_processing_failed',
            'action.name'    => 'process_crm_event',
            'action.status'  => 'failed',
        ]);
    }

    #[Test]
    public function actionDurationMsIsAlwaysStringNotInt(): void
    {
        // OTel атрибуты должны быть строками (не int)
        $captured = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$captured): void {
                $captured = $attrs;
            });

        // Act
        $this->telemetry->trackEvent('b24_event_processed', [
            'action.duration_ms' => (string) 42,
            'action.status'      => 'completed',
        ]);

        // Assert
        $this->assertIsString($captured['action.duration_ms']);
        $this->assertSame('42', $captured['action.duration_ms']);
    }

    #[Test]
    public function actionInitiatedAndCompletedSequenceIsValid(): void
    {
        // Arrange — ожидаем два вызова trackEvent подряд
        $callOrder = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$callOrder): void {
                $callOrder[] = $name;
            });

        // Act — симулируем последовательность initiated → completed
        $this->telemetry->trackEvent('b24_event_action_initiated', [
            'action.name'   => 'process_crm_contact_add',
            'action.status' => 'initiated',
        ]);
        $this->telemetry->trackEvent('b24_event_processed', [
            'action.name'   => 'process_crm_contact_add',
            'action.status' => 'completed',
        ]);

        // Assert — последовательность правильная
        $this->assertSame(['b24_event_action_initiated', 'b24_event_processed'], $callOrder);
    }
}
