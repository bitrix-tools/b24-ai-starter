<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\MonologOTelHandler;
use App\Service\Telemetry\TelemetryInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для MonologOTelHandler.
 *
 * @covers \App\Service\Telemetry\MonologOTelHandler
 */
class MonologOTelHandlerTest extends TestCase
{
    /**
     * Тест: INFO лог преобразуется в trackEvent с корректными атрибутами.
     */
    public function testInfoLogConvertsToTrackEvent(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->equalTo('log.info.app'),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('log.level', $attributes);
                    $this->assertSame('INFO', $attributes['log.level']);
                    $this->assertArrayHasKey('log.severity', $attributes);
                    $this->assertSame('INFO', $attributes['log.severity']);
                    $this->assertArrayHasKey('log.channel', $attributes);
                    $this->assertSame('app', $attributes['log.channel']);
                    $this->assertArrayHasKey('log.message', $attributes);
                    $this->assertSame('Test message', $attributes['log.message']);
                    $this->assertArrayHasKey('log.timestamp', $attributes);
                    $this->assertIsInt($attributes['log.timestamp']);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: ERROR лог с exception вызывает trackError.
     */
    public function testErrorLogWithExceptionCallsTrackError(): void
    {
        $exception = new \RuntimeException('Test exception');

        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackError')
            ->with(
                $this->identicalTo($exception),
                $this->callback(function (array $context) {
                    $this->assertArrayHasKey('log.level', $context);
                    $this->assertSame('ERROR', $context['log.level']);
                    $this->assertArrayHasKey('log.severity', $context);
                    $this->assertSame('ERROR', $context['log.severity']);
                    $this->assertArrayNotHasKey('exception', $context);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'Error occurred',
            context: ['exception' => $exception],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: context атрибуты добавляются с префиксом "context.".
     */
    public function testContextAttributesArePrefixed(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('context.user_id', $attributes);
                    $this->assertSame(123, $attributes['context.user_id']);
                    $this->assertArrayHasKey('context.action', $attributes);
                    $this->assertSame('login', $attributes['context.action']);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'User action',
            context: [
                'user_id' => 123,
                'action' => 'login',
            ],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: extra атрибуты добавляются с префиксом "extra.".
     */
    public function testExtraAttributesArePrefixed(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('extra.request_id', $attributes);
                    $this->assertSame('req-123', $attributes['extra.request_id']);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Request processed',
            context: [],
            extra: ['request_id' => 'req-123'],
        );

        $handler->handle($record);
    }

    /**
     * Тест: массивы в context нормализуются в JSON.
     */
    public function testArrayContextIsNormalizedToJson(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('context.data', $attributes);
                    $this->assertIsString($attributes['context.data']);
                    $decoded = json_decode($attributes['context.data'], true);
                    $this->assertIsArray($decoded);
                    $this->assertSame(['key' => 'value'], $decoded);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Data received',
            context: [
                'data' => ['key' => 'value'],
            ],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: при отключенной телеметрии handler ничего не делает.
     */
    public function testNoActionWhenTelemetryDisabled(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);

        $telemetry->expects($this->never())
            ->method('trackEvent');
        $telemetry->expects($this->never())
            ->method('trackError');

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: различные уровни логов правильно маппятся в severity.
     */
    public function testLogLevelsSeverityMapping(): void
    {
        $testCases = [
            [Level::Debug, 'DEBUG'],
            [Level::Info, 'INFO'],
            [Level::Notice, 'INFO'],
            [Level::Warning, 'WARN'],
            [Level::Error, 'ERROR'],
            [Level::Critical, 'FATAL'],
            [Level::Alert, 'FATAL'],
            [Level::Emergency, 'FATAL'],
        ];

        foreach ($testCases as [$level, $expectedSeverity]) {
            $telemetry = $this->createMock(TelemetryInterface::class);
            $telemetry->method('isEnabled')->willReturn(true);

            $telemetry->expects($this->once())
                ->method('trackEvent')
                ->with(
                    $this->anything(),
                    $this->callback(function (array $attributes) use ($expectedSeverity) {
                        $this->assertSame($expectedSeverity, $attributes['log.severity']);

                        return true;
                    }),
                );

            $handler = new MonologOTelHandler($telemetry, Level::Debug);

            $record = new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: 'app',
                level: $level,
                message: 'Test',
                context: [],
                extra: [],
            );

            $handler->handle($record);
        }
    }

    /**
     * Тест: имя события формируется корректно из level и channel.
     */
    public function testEventNameFormat(): void
    {
        $testCases = [
            ['app', Level::Info, 'log.info.app'],
            ['security', Level::Warning, 'log.warning.security'],
            ['doctrine', Level::Error, 'log.error.doctrine'],
        ];

        foreach ($testCases as [$channel, $level, $expectedEventName]) {
            $telemetry = $this->createMock(TelemetryInterface::class);
            $telemetry->method('isEnabled')->willReturn(true);

            $telemetry->expects($this->once())
                ->method('trackEvent')
                ->with($this->equalTo($expectedEventName), $this->anything());

            $handler = new MonologOTelHandler($telemetry, Level::Debug);

            $record = new LogRecord(
                datetime: new \DateTimeImmutable(),
                channel: $channel,
                level: $level,
                message: 'Test',
                context: [],
                extra: [],
            );

            $handler->handle($record);
        }
    }

    /**
     * Тест: null значения в context нормализуются в пустую строку.
     */
    public function testNullValuesAreNormalized(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('isEnabled')->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('context.nullable', $attributes);
                    $this->assertSame('', $attributes['context.nullable']);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test',
            context: ['nullable' => null],
            extra: [],
        );

        $handler->handle($record);
    }

    /**
     * Тест: объект без __toString преобразуется в имя класса.
     */
    public function testObjectWithoutToStringBecomesClassName(): void
    {
        $object = new \stdClass();

        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->method('isEnabled')->willReturn(true);

        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with(
                $this->anything(),
                $this->callback(function (array $attributes) {
                    $this->assertArrayHasKey('context.object', $attributes);
                    $this->assertSame('stdClass', $attributes['context.object']);

                    return true;
                }),
            );

        $handler = new MonologOTelHandler($telemetry, Level::Debug);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'Test',
            context: ['object' => $object],
            extra: [],
        );

        $handler->handle($record);
    }
}
