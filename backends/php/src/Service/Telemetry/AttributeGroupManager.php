<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use App\Service\Telemetry\Profiles\ProfileInterface;

/**
 * AttributeGroupManager — менеджер для композиции профилей и фильтрации атрибутов.
 *
 * Управляет набором активных профилей и определяет, какие атрибуты разрешены
 * для отправки в телеметрию. Поддерживает:
 * - Композицию нескольких профилей (например, Lifecycle + UI)
 * - Дедупликацию атрибутов при пересечении профилей
 * - Exclusion patterns для тонкой настройки (wildcard поддержка)
 * - Фильтрацию атрибутов событий
 *
 * **Пример использования:**
 * ```php
 * $manager = new AttributeGroupManager(
 *     [$lifecycleProfile, $uiProfile],
 *     ['sync.*', 'migration.*'] // exclude patterns
 * );
 *
 * $allowed = $manager->getAllowedAttributes(); // все разрешённые атрибуты
 * $filtered = $manager->filterAttributes($eventAttributes); // фильтрация
 * ```
 */
class AttributeGroupManager
{
    /**
     * @var array<string> Кэшированный список всех разрешённых атрибутов
     */
    private ?array $allowedAttributesCache = null;

    /**
     * @param array<ProfileInterface> $profiles        Список активных профилей
     * @param array<string>           $excludePatterns Паттерны для исключения атрибутов (поддержка wildcard *)
     */
    public function __construct(
        private readonly array $profiles,
        private readonly array $excludePatterns = [],
    ) {
        $this->validateProfiles();
        $this->validateExcludePatterns();
    }

    /**
     * Возвращает список всех разрешённых атрибутов из всех профилей
     * с учётом дедупликации и exclusion patterns.
     *
     * @return array<string> Список уникальных атрибутов
     */
    public function getAllowedAttributes(): array
    {
        if (null !== $this->allowedAttributesCache) {
            return $this->allowedAttributesCache;
        }

        // Собираем все атрибуты из всех профилей
        $allAttributes = [];
        foreach ($this->profiles as $profile) {
            $allAttributes = array_merge($allAttributes, $profile->getAllowedAttributes());
        }

        // Дедупликация
        $uniqueAttributes = array_unique($allAttributes);

        // Применяем exclusion patterns
        $filtered = array_filter($uniqueAttributes, fn ($attr): bool => !$this->isExcluded($attr));

        // Кэшируем результат
        $this->allowedAttributesCache = array_values($filtered);

        return $this->allowedAttributesCache;
    }

    /**
     * Фильтрует атрибуты события, оставляя только разрешённые.
     *
     * @param array<string, mixed> $attributes Атрибуты события для фильтрации
     *
     * @return array<string, mixed> Отфильтрованные атрибуты (только разрешённые)
     */
    public function filterAttributes(array $attributes): array
    {
        $allowed = $this->getAllowedAttributes();

        return array_filter(
            $attributes,
            fn ($key): bool => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Проверяет, разрешён ли атрибут (есть ли в хотя бы одном профиле и не исключён паттерном).
     *
     * @param string $attributeName Имя атрибута для проверки
     *
     * @return bool True если атрибут разрешён, иначе false
     */
    public function isAttributeAllowed(string $attributeName): bool
    {
        return in_array($attributeName, $this->getAllowedAttributes(), true);
    }

    /**
     * Возвращает список атрибутов, которые были отфильтрованы (не разрешены).
     *
     * @param array<string, mixed> $attributes Исходные атрибуты события
     *
     * @return array<string> Список имён отфильтрованных атрибутов
     */
    public function getFilteredOutAttributes(array $attributes): array
    {
        $allowed = $this->getAllowedAttributes();

        $filteredOut = array_filter(
            array_keys($attributes),
            fn ($key): bool => !in_array($key, $allowed, true),
        );

        return array_values($filteredOut);
    }

    /**
     * Возвращает список активных профилей.
     *
     * @return array<ProfileInterface>
     */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    /**
     * Возвращает список exclusion patterns.
     *
     * @return array<string>
     */
    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /**
     * Возвращает количество активных профилей.
     */
    public function getProfileCount(): int
    {
        return count($this->profiles);
    }

    /**
     * Возвращает количество разрешённых атрибутов (после дедупликации и фильтрации).
     */
    public function getAllowedAttributeCount(): int
    {
        return count($this->getAllowedAttributes());
    }

    /**
     * Проверяет, исключён ли атрибут по exclusion patterns (wildcard поддержка).
     *
     * @param string $attributeName Имя атрибута для проверки
     *
     * @return bool True если атрибут исключён, иначе false
     */
    private function isExcluded(string $attributeName): bool
    {
        foreach ($this->excludePatterns as $excludePattern) {
            if ($this->matchesPattern($attributeName, $excludePattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверяет соответствие атрибута паттерну (поддержка wildcard *).
     *
     * @param string $attributeName Имя атрибута
     * @param string $pattern       Паттерн с возможным wildcard (например, sync.*, *.id)
     *
     * @return bool True если атрибут соответствует паттерну
     */
    private function matchesPattern(string $attributeName, string $pattern): bool
    {
        // Точное совпадение
        if ($attributeName === $pattern) {
            return true;
        }

        // Wildcard паттерн
        if (str_contains($pattern, '*')) {
            // Конвертируем wildcard паттерн в regex
            // sync.* -> /^sync\..*/
            // *.id -> /^.*\.id$/
            $regex = '/^'.str_replace(
                ['\\*', '\\.'],
                ['.*', '\\.'],
                preg_quote($pattern, '/'),
            ).'$/';

            return 1 === preg_match($regex, $attributeName);
        }

        return false;
    }

    /**
     * Валидация списка профилей.
     *
     * @throws \InvalidArgumentException Если профили невалидны
     */
    private function validateProfiles(): void
    {
        if ([] === $this->profiles) {
            throw new \InvalidArgumentException('At least one profile must be provided');
        }

        foreach ($this->profiles as $profile) {
            if (!$profile instanceof ProfileInterface) {
                throw new \InvalidArgumentException(sprintf('Profile must implement ProfileInterface, got: %s', get_debug_type($profile)));
            }
        }
    }

    /**
     * Валидация exclusion patterns.
     *
     * @throws \InvalidArgumentException Если паттерны невалидны
     */
    private function validateExcludePatterns(): void
    {
        foreach ($this->excludePatterns as $excludePattern) {
            if (!is_string($excludePattern) || '' === $excludePattern) {
                throw new \InvalidArgumentException(sprintf('Exclude pattern must be a non-empty string, got: %s', var_export($excludePattern, true)));
            }
        }
    }
}
