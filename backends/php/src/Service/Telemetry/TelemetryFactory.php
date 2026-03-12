<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use App\Service\Telemetry\Config\OtlpConfig;
use App\Service\Telemetry\Profiles\ProfileInterface;
use Psr\Log\LoggerInterface;

/**
 * Фабрика для создания сервиса телеметрии.
 *
 * Выбирает реализацию на основе конфигурации:
 * - TELEMETRY_ENABLED=false → NullTelemetryService (zero overhead)
 * - TELEMETRY_ENABLED=true → RealTelemetryService (с fallback на Null при ошибках)
 *
 * Создает AttributeGroupManager на основе активного профиля из конфигурации.
 */
final class TelemetryFactory
{
    /**
     * @param array<string, array{profiles: list<class-string<ProfileInterface>>, exclude_patterns: list<string>, description: string}> $profilesConfig
     */
    public function __construct(
        private readonly bool $telemetryEnabled,
        private readonly string $otlpEndpoint,
        private readonly string $serviceName = 'b24-app',
        private readonly string $serviceVersion = '1.0.0',
        private readonly string $environment = 'development',
        private readonly ?LoggerInterface $logger = null,
        private readonly string $activeProfile = 'simple-ui',
        private readonly array $profilesConfig = [],
    ) {
    }

    /**
     * Создает экземпляр TelemetryInterface.
     */
    public function create(): TelemetryInterface
    {
        if (!$this->telemetryEnabled) {
            return new NullTelemetryService();
        }

        try {
            // Создаем конфигурацию OTLP
            $otlpConfig = new OtlpConfig(
                $this->otlpEndpoint,
                $this->serviceName,
                $this->serviceVersion,
                $this->environment,
            );

            // Создаем AttributeGroupManager на основе активного профиля
            $attributeGroupManager = $this->createAttributeGroupManager();

            // Создаем реальный сервис телеметрии
            $realTelemetryService = new RealTelemetryService(
                $otlpConfig,
                $attributeGroupManager,
                $this->logger,
            );

            // Проверяем что сервис инициализировался успешно
            if (!$realTelemetryService->isEnabled()) {
                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->warning(
                        'RealTelemetryService failed to initialize, falling back to NullTelemetryService',
                    );
                }

                return new NullTelemetryService();
            }

            if ($this->logger instanceof LoggerInterface) {
                $this->logger->info('Telemetry enabled', [
                    'service' => RealTelemetryService::class,
                    'config' => $otlpConfig->toArray(),
                    'active_profile' => $this->activeProfile,
                ]);
            }

            return $realTelemetryService;
        } catch (\Throwable $throwable) {
            // При любых ошибках инициализации возвращаем Null-реализацию
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to create RealTelemetryService, using NullTelemetryService', [
                    'error' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
            }

            return new NullTelemetryService();
        }
    }

    /**
     * Создает AttributeGroupManager на основе активного профиля из конфигурации.
     */
    private function createAttributeGroupManager(): ?AttributeGroupManager
    {
        // Если конфигурация профилей не задана - возвращаем null (без фильтрации)
        if ([] === $this->profilesConfig) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->warning('No profiles configuration provided, attribute filtering disabled');
            }

            return null;
        }

        // Проверяем что активный профиль существует в конфигурации
        if (!isset($this->profilesConfig[$this->activeProfile])) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Active profile not found in configuration', [
                    'active_profile' => $this->activeProfile,
                    'available_profiles' => array_keys($this->profilesConfig),
                ]);
            }

            return null;
        }

        // Получаем конфигурацию активного профиля
        $profileConfig = $this->profilesConfig[$this->activeProfile];
        $profileClasses = $profileConfig['profiles'] ?? [];
        $excludePatterns = $profileConfig['exclude_patterns'] ?? [];

        // Инстанцируем профили
        $profiles = [];
        foreach ($profileClasses as $profileClass) {
            try {
                // Профили должны иметь публичный конструктор без параметров
                $profile = new $profileClass();

                if (!$profile instanceof ProfileInterface) {
                    if ($this->logger instanceof LoggerInterface) {
                        $this->logger->error('Profile class does not implement ProfileInterface', [
                            'profile_class' => $profileClass,
                        ]);
                    }

                    continue;
                }

                $profiles[] = $profile;
            } catch (\Throwable $throwable) {
                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->error('Failed to instantiate profile', [
                        'profile_class' => $profileClass,
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }
        }

        // Если не удалось создать ни одного профиля - возвращаем null
        if ([] === $profiles) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('No profiles were successfully instantiated');
            }

            return null;
        }

        // Создаем и возвращаем AttributeGroupManager
        try {
            $attributeGroupManager = new AttributeGroupManager($profiles, $excludePatterns);

            if ($this->logger instanceof LoggerInterface) {
                $this->logger->info('AttributeGroupManager created', [
                    'active_profile' => $this->activeProfile,
                    'profiles_count' => count($profiles),
                    'exclude_patterns' => $excludePatterns,
                    'allowed_attributes_count' => count($attributeGroupManager->getAllowedAttributes()),
                ]);
            }

            return $attributeGroupManager;
        } catch (\Throwable $throwable) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to create AttributeGroupManager', [
                    'error' => $throwable->getMessage(),
                ]);
            }

            return null;
        }
    }
}
