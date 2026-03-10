<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Telemetry\TelemetryInterface;
use Bitrix24\SDK\Core\Exceptions\BaseException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listener для автоматического отслеживания необработанных исключений (Sprint 5, Step 5.6).
 *
 * Перехватывает все исключения HTTP-запросов и отправляет их в телеметрию
 * через trackError() с классификацией по категориям:
 * - validation_error: ошибки валидации данных
 * - not_found: сущность или маршрут не найден
 * - auth_error: ошибки аутентификации/авторизации
 * - api_error: ошибки при вызове внешних API (Bitrix24 SDK)
 * - internal_error: необработанные внутренние ошибки
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class TelemetryExceptionListener
{
    public function __construct(
        private readonly TelemetryInterface $telemetry,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->telemetry->isEnabled()) {
            return;
        }

        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        // Классифицируем ошибку по типу
        $category = $this->classifyException($throwable);

        $this->telemetry->trackError($throwable, [
            'error.category'   => $category,
            'request.path'     => $request->getPathInfo(),
            'request.method'   => $request->getMethod(),
            'error.class'      => get_class($throwable),
            'error.http_status' => $throwable instanceof HttpExceptionInterface
                ? (string) $throwable->getStatusCode()
                : '500',
        ]);
    }

    /**
     * Классифицирует исключение по категории для атрибута error.category.
     */
    private function classifyException(\Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof NotFoundHttpException        => 'not_found',
            $this->isAuthException($throwable)                 => 'auth_error',
            $this->isValidationException($throwable)           => 'validation_error',
            $throwable instanceof BaseException                => 'api_error',
            $throwable instanceof \InvalidArgumentException    => 'validation_error',
            $throwable instanceof \DomainException             => 'domain_error',
            default                                            => 'internal_error',
        };
    }

    private function isAuthException(\Throwable $throwable): bool
    {
        $class = get_class($throwable);

        return str_contains(strtolower($class), 'auth')
            || str_contains(strtolower($class), 'unauthorized')
            || str_contains(strtolower($class), 'forbidden')
            || str_contains(strtolower($class), 'jwt');
    }

    private function isValidationException(\Throwable $throwable): bool
    {
        $class = get_class($throwable);

        return str_contains(strtolower($class), 'validation')
            || str_contains(strtolower($class), 'constraint');
    }
}
