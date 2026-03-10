<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * BaseProfile — базовый класс для профилей телеметрии.
 *
 * Предоставляет общую логику для всех профилей:
 * - Хранение списка атрибутов
 * - Валидация имён атрибутов (semantic conventions)
 * - Проверка принадлежности атрибута профилю
 * - Дедупликация атрибутов при композиции профилей
 *
 * Наследники должны:
 * - Переопределить $name
 * - Переопределить $description
 * - Определить список атрибутов через метод defineAttributes()
 */
abstract class BaseProfile implements ProfileInterface
{
    /**
     * @var array<string> Кэшированный список разрешённых атрибутов
     */
    private ?array $allowedAttributes = null;

    /**
     * Имя профиля (должно быть переопределено в наследниках).
     */
    protected string $name = 'base';

    /**
     * Описание профиля (должно быть переопределено в наследниках).
     */
    protected string $description = 'Base profile';

    public function getAllowedAttributes(): array
    {
        if (null === $this->allowedAttributes) {
            $this->allowedAttributes = $this->defineAttributes();
            $this->validateAttributes($this->allowedAttributes);
        }

        return $this->allowedAttributes;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Проверяет, разрешён ли атрибут в рамках профиля.
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
     * Возвращает количество разрешённых атрибутов в профиле.
     *
     * @return int Количество атрибутов
     */
    public function getAttributeCount(): int
    {
        return count($this->getAllowedAttributes());
    }

    /**
     * Определяет список атрибутов профиля
     * Должен быть переопределён в наследниках.
     *
     * @return array<string> Список имён атрибутов
     */
    abstract protected function defineAttributes(): array;

    /**
     * Валидация имён атрибутов (semantic conventions).
     *
     * Проверяет, что все атрибуты соответствуют формату:
     * - Lowercase с underscores: 'attribute_name'
     * - Точечная нотация для группировки: 'group.subgroup.attribute'
     * - Только буквы, цифры, точки и underscores
     *
     * @param array<string> $attributes Список атрибутов для валидации
     *
     * @throws \InvalidArgumentException Если атрибут не соответствует формату
     */
    private function validateAttributes(array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (!is_string($attribute) || '' === $attribute) {
                throw new \InvalidArgumentException(sprintf('Attribute name must be a non-empty string, got: %s', var_export($attribute, true)));
            }

            // Semantic conventions: lowercase with dots and underscores
            // Examples: app.id, lifecycle.event_type, external.system_name
            if (!preg_match('/^[a-z][a-z0-9._]*[a-z0-9]$/', $attribute)) {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" does not follow semantic conventions. Expected format: lowercase with dots and underscores (e.g., "app.id", "lifecycle.event_type")', $attribute));
            }
        }
    }
}
