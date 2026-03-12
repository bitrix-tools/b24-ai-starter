<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\Config\OtlpConfig;
use App\Service\Telemetry\NullTelemetryService;
use App\Service\Telemetry\RealTelemetryService;
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты метода trackOperation() — замер длительности операций с записью в otel_traces.
 *
 * Покрывает:
 * - Контракт TelemetryInterface::trackOperation()
 * - NullTelemetryService: zero-overhead выполнение
 * - RealTelemetryService: создание span, возврат результата, проброс исключений
 * - Граничные случаи: null-результат, массивы, вложенные вызовы, unavailable collector
 */
class TrackOperationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Контракт интерфейса
    // -------------------------------------------------------------------------

    #[Test]
    public function interfaceDefinesTrackOperationMethod(): void
    {
        $reflection = new \ReflectionClass(TelemetryInterface::class);

        $this->assertTrue(
            $reflection->hasMethod('trackOperation'),
            'TelemetryInterface must declare trackOperation()',
        );
    }

    #[Test]
    public function trackOperationMethodSignatureIsCorrect(): void
    {
        $reflection = new \ReflectionClass(TelemetryInterface::class);
        $method = $reflection->getMethod('trackOperation');
        $params = $method->getParameters();

        $this->assertCount(3, $params, 'trackOperation must have 3 parameters');
        $this->assertSame('name', $params[0]->getName());
        $this->assertSame('operation', $params[1]->getName());
        $this->assertSame('attributes', $params[2]->getName());
        $this->assertTrue($params[2]->isOptional(), '$attributes must be optional');
        $this->assertSame([], $params[2]->getDefaultValue(), '$attributes default must be []');
    }

    #[Test]
    public function nullServiceImplementsTrackOperation(): void
    {
        $service = new NullTelemetryService();
        $this->assertTrue(method_exists($service, 'trackOperation'));
    }

    #[Test]
    public function realServiceImplementsTrackOperation(): void
    {
        $service = $this->createRealService();
        $this->assertTrue(method_exists($service, 'trackOperation'));
    }

    // -------------------------------------------------------------------------
    // NullTelemetryService — zero overhead
    // -------------------------------------------------------------------------

    #[Test]
    public function nullServiceTrackOperationReturnsScalarResult(): void
    {
        $service = new NullTelemetryService();

        $this->assertSame(42, $service->trackOperation('op', fn () => 42));
        $this->assertSame('hello', $service->trackOperation('op', fn () => 'hello'));
        $this->assertSame(3.14, $service->trackOperation('op', fn () => 3.14));
        $this->assertTrue($service->trackOperation('op', fn () => true));
    }

    #[Test]
    public function nullServiceTrackOperationReturnsNullResult(): void
    {
        $service = new NullTelemetryService();

        $this->assertNull($service->trackOperation('op.null', fn () => null));
    }

    #[Test]
    public function nullServiceTrackOperationReturnsArrayResult(): void
    {
        $service = new NullTelemetryService();
        $expected = ['id' => 1, 'name' => 'Test Contact'];

        $result = $service->trackOperation('bitrix24.crm.contact.get', fn () => $expected);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function nullServiceTrackOperationReturnsObjectResult(): void
    {
        $service = new NullTelemetryService();
        $obj = new \stdClass();
        $obj->value = 'test';

        $result = $service->trackOperation('op.object', fn () => $obj);

        $this->assertSame($obj, $result);
    }

    #[Test]
    public function nullServiceTrackOperationRethrowsException(): void
    {
        $service = new NullTelemetryService();
        $original = new \RuntimeException('upstream error', 503);

        $thrown = null;

        try {
            $service->trackOperation('op.fail', function () use ($original): never {
                throw $original;
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame($original, $thrown, 'Must rethrow the exact same exception');
    }

    #[Test]
    public function nullServiceTrackOperationCallsOperationExactlyOnce(): void
    {
        $service = new NullTelemetryService();
        $calls = 0;

        $service->trackOperation('op.calls', function () use (&$calls): void {
            ++$calls;
        });

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function nullServiceTrackOperationWithAttributesDoesNotThrow(): void
    {
        $service = new NullTelemetryService();

        $this->expectNotToPerformAssertions();
        $service->trackOperation(
            'ai.claude.messages.create',
            fn () => ['id' => 'msg_001'],
            ['ai.model' => 'claude-3-5-sonnet', 'portal.member_id' => 'p123'],
        );
    }

    // -------------------------------------------------------------------------
    // RealTelemetryService — поведение с реальным SDK
    // -------------------------------------------------------------------------

    #[Test]
    public function realServiceTrackOperationReturnsResult(): void
    {
        $service = $this->createRealService();

        $result = $service->trackOperation('test.operation', fn () => 'span_result');

        $this->assertSame('span_result', $result);
    }

    #[Test]
    public function realServiceTrackOperationCallsOperationExactlyOnce(): void
    {
        $service = $this->createRealService();
        $calls = 0;

        $service->trackOperation('test.count', function () use (&$calls): void {
            ++$calls;
        });

        $this->assertSame(1, $calls);
    }

    #[Test]
    public function realServiceTrackOperationRethrowsException(): void
    {
        $service = $this->createRealService();
        $original = new \InvalidArgumentException('bad input', 400);

        $thrown = null;

        try {
            $service->trackOperation('op.fail', function () use ($original): never {
                throw $original;
            });
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Exception must be rethrown');
        $this->assertSame($original, $thrown, 'Must rethrow the exact same exception');
    }

    #[Test]
    public function realServiceTrackOperationDoesNotSwallowErrorThrowable(): void
    {
        $service = $this->createRealService();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('invariant violated');

        $service->trackOperation('op.logic', function (): never {
            throw new \LogicException('invariant violated');
        });
    }

    #[Test]
    public function realServiceTrackOperationWithArrayAttributesDoesNotThrow(): void
    {
        // Массивы в атрибутах проходят через flattenAttributes() -> JSON строка
        $service = $this->createRealService();

        $this->expectNotToPerformAssertions();
        $service->trackOperation(
            'test.array_attrs',
            fn () => null,
            ['metadata' => ['key' => 'value', 'count' => 5]],
        );
    }

    #[Test]
    public function realServiceTrackOperationNestedCallPreservesReturnValues(): void
    {
        $service = $this->createRealService();

        $innerResult = null;
        $outerResult = $service->trackOperation('outer', function () use ($service, &$innerResult): string {
            $innerResult = $service->trackOperation('inner', fn () => 'from_inner');

            return 'from_outer';
        });

        $this->assertSame('from_outer', $outerResult);
        $this->assertSame('from_inner', $innerResult);
    }

    #[Test]
    public function realServiceTrackOperationNestedExceptionPropagatesCorrectly(): void
    {
        $service = $this->createRealService();

        $original = new \RuntimeException('inner failure');
        $caught = null;

        try {
            $service->trackOperation('outer', function () use ($service, $original): void {
                $service->trackOperation('inner', function () use ($original): never {
                    throw $original;
                });
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame($original, $caught, 'Exception from inner span must propagate to caller');
    }

    #[Test]
    public function realServiceTrackOperationWithUnavailableCollectorStillExecutesOperation(): void
    {
        // При недоступном коллекторе операция всё равно выполняется и возвращает результат
        $config = new OtlpConfig(
            'http://localhost:65535',
            'test-service',
            '1.0.0',
            'test',
        );
        $service = new RealTelemetryService($config, null, new NullLogger());

        $result = $service->trackOperation(
            'test.unreachable',
            fn () => ['status' => 'ok'],
        );

        $this->assertSame(['status' => 'ok'], $result);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function scalarResultProvider(): array
    {
        return [
            'integer' => ['test.int',    42],
            'string' => ['test.string', 'result'],
            'float' => ['test.float',  3.14],
            'bool_true' => ['test.true',   true],
            'bool_false' => ['test.false',  false],
            'null' => ['test.null',   null],
        ];
    }

    #[Test]
    #[DataProvider('scalarResultProvider')]
    public function nullServicePreservesScalarReturnValues(string $name, mixed $value): void
    {
        $service = new NullTelemetryService();

        $result = $service->trackOperation($name, fn () => $value);

        $this->assertSame($value, $result);
    }

    #[Test]
    #[DataProvider('scalarResultProvider')]
    public function realServicePreservesScalarReturnValues(string $name, mixed $value): void
    {
        $service = $this->createRealService();

        $result = $service->trackOperation($name, fn () => $value);

        $this->assertSame($value, $result);
    }

    // -------------------------------------------------------------------------
    // Сравнение NullService vs RealService поведения
    // -------------------------------------------------------------------------

    #[Test]
    public function bothImplementationsBehaveIdenticallyForNormalOperation(): void
    {
        $null = new NullTelemetryService();
        $real = $this->createRealService();

        $payload = ['id' => 7, 'name' => 'contact'];

        $nullResult = $null->trackOperation('op', fn () => $payload, ['key' => 'val']);
        $realResult = $real->trackOperation('op', fn () => $payload, ['key' => 'val']);

        $this->assertSame($nullResult, $realResult, 'Both implementations must return the same result');
    }

    #[Test]
    public function bothImplementationsRethrowExceptions(): void
    {
        $null = new NullTelemetryService();
        $real = $this->createRealService();

        $exception = new \RuntimeException('fail');

        foreach ([$null, $real] as $service) {
            $caught = null;

            try {
                $service->trackOperation('op.fail', function () use ($exception): never {
                    throw $exception;
                });
            } catch (\RuntimeException $e) {
                $caught = $e;
            }

            $this->assertSame($exception, $caught, get_class($service).' must rethrow the exception');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createRealService(): RealTelemetryService
    {
        $config = new OtlpConfig(
            'http://localhost:4318',
            'test-service',
            '1.0.0-test',
            'test',
        );

        return new RealTelemetryService($config, null, new NullLogger());
    }
}
