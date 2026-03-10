<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\EventListener\TelemetryExceptionListener;
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Тесты отслеживания ошибок через TelemetryExceptionListener (Sprint 5, Step 5.6).
 *
 * Проверяет:
 * - Исключения автоматически передаются в trackError
 * - Категории ошибок классифицируются корректно
 * - При отключённой телеметрии listener не вызывает trackError
 * - request.path и request.method включены в контекст
 */
class ErrorTrackingTest extends TestCase
{
    private TelemetryInterface&MockObject $telemetry;
    private HttpKernelInterface&MockObject $kernel;

    protected function setUp(): void
    {
        $this->telemetry = $this->createMock(TelemetryInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    private function makeEvent(\Throwable $throwable, string $path = '/api/test'): ExceptionEvent
    {
        $request = Request::create($path, 'GET');

        return new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    // ------------------------------------------------------------------
    // Базовая функциональность
    // ------------------------------------------------------------------

    #[Test]
    public function trackErrorCalledOnException(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $exception = new \RuntimeException('Something failed');

        $this->telemetry
            ->expects($this->once())
            ->method('trackError')
            ->with(
                $this->identicalTo($exception),
                $this->callback(fn(array $ctx) =>
                    isset($ctx['error.category'])
                    && isset($ctx['request.path'])
                    && isset($ctx['request.method'])
                )
            );

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent($exception, '/api/test'));
    }

    #[Test]
    public function noTrackErrorWhenTelemetryDisabled(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(false);

        $this->telemetry
            ->expects($this->never())
            ->method('trackError');

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act — при выключенной телеметрии ничего не вызывается
        $listener->onKernelException($this->makeEvent(new \RuntimeException('test')));
    }

    // ------------------------------------------------------------------
    // Классификация ошибок
    // ------------------------------------------------------------------

    #[Test]
    public function notFoundExceptionClassifiedCorrectly(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent(new NotFoundHttpException('Route not found')));

        // Assert
        $this->assertSame('not_found', $captured['error.category']);
    }

    #[Test]
    public function invalidArgumentExceptionClassifiedAsValidationError(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent(new \InvalidArgumentException('Bad input')));

        // Assert
        $this->assertSame('validation_error', $captured['error.category']);
    }

    #[Test]
    public function internalExceptionDefaultsToInternalError(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent(new \RuntimeException('Unexpected failure')));

        // Assert
        $this->assertSame('internal_error', $captured['error.category']);
    }

    #[Test]
    public function requestPathAndMethodIncludedInContext(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);
        $exception = new \RuntimeException('Test error');
        $event = $this->makeEvent($exception, '/api/list');

        // Act
        $listener->onKernelException($event);

        // Assert
        $this->assertArrayHasKey('request.path', $captured);
        $this->assertSame('/api/list', $captured['request.path']);
        $this->assertArrayHasKey('request.method', $captured);
        $this->assertSame('GET', $captured['request.method']);
    }

    #[Test]
    public function errorClassIsIncludedInContext(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent(new \RuntimeException('test')));

        // Assert
        $this->assertArrayHasKey('error.class', $captured);
        $this->assertSame(\RuntimeException::class, $captured['error.class']);
    }

    #[Test]
    public function domainExceptionClassifiedCorrectly(): void
    {
        // Arrange
        $this->telemetry->method('isEnabled')->willReturn(true);

        $captured = [];
        $this->telemetry
            ->method('trackError')
            ->willReturnCallback(function (\Throwable $t, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $listener = new TelemetryExceptionListener($this->telemetry);

        // Act
        $listener->onKernelException($this->makeEvent(new \DomainException('Business rule violated')));

        // Assert
        $this->assertSame('domain_error', $captured['error.category']);
    }
}
