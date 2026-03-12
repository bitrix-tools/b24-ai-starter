<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Frontend;

use App\DTO\FrontendTelemetryEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты DTO FrontendTelemetryEvent (Sprint 8, Step 8.3).
 *
 * Проверяет:
 * - Успешное создание при валидных данных
 * - Отклонение неизвестных имён событий
 * - Отклонение при превышении лимита атрибутов (>30)
 * - Отклонение при слишком длинном значении атрибута (>512 символов)
 * - Отклонение при некорректных ключах атрибутов
 * - Корректное хранение client_timestamp_ms
 * - Опциональность полей attributes и client_timestamp_ms
 */
class FrontendTelemetryEventTest extends TestCase
{
    private const array WHITELIST = [
        'page_view',
        'ui_button_click',
        'ui_form_submit',
        'ui_error',
        'app_frame_loaded',
    ];

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    #[Test]
    public function happyPathValidEventWithAttributes(): void
    {
        $event = FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => [
                'ui.path' => '/crm/leads',
                'ui.route_name' => 'crm-leads',
            ],
            'client_timestamp_ms' => 1740000000000,
        ], self::WHITELIST);

        self::assertSame('page_view', $event->getEventName());
        self::assertSame('/crm/leads', $event->getAttributes()['ui.path']);
        self::assertSame('crm-leads', $event->getAttributes()['ui.route_name']);
        self::assertSame(1740000000000, $event->getClientTimestampMs());
    }

    #[Test]
    public function happyPathNoAttributesOrTimestamp(): void
    {
        $event = FrontendTelemetryEvent::fromArray([
            'event_name' => 'app_frame_loaded',
        ], self::WHITELIST);

        self::assertSame('app_frame_loaded', $event->getEventName());
        self::assertSame([], $event->getAttributes());
        self::assertNull($event->getClientTimestampMs());
    }

    #[Test]
    public function happyPathNonStringValuesAreCastToString(): void
    {
        $event = FrontendTelemetryEvent::fromArray([
            'event_name' => 'ui_button_click',
            'attributes' => [
                'ui.count' => 42,
                'ui.active' => true,
            ],
        ], self::WHITELIST);

        self::assertSame('42', $event->getAttributes()['ui.count']);
        self::assertSame('1', $event->getAttributes()['ui.active']);
    }

    #[Test]
    public function happyPathExactlyMaxAttributes(): void
    {
        $attributes = [];
        for ($i = 1; $i <= 30; ++$i) {
            $attributes['attr.'.$i] = 'value';
        }

        $event = FrontendTelemetryEvent::fromArray([
            'event_name' => 'ui_form_submit',
            'attributes' => $attributes,
        ], self::WHITELIST);

        self::assertCount(30, $event->getAttributes());
    }

    // ------------------------------------------------------------------
    // Whitelist validation
    // ------------------------------------------------------------------

    #[Test]
    public function rejectUnknownEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown event name "custom_hack"/');

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'custom_hack',
        ], self::WHITELIST);
    }

    #[Test]
    public function rejectMissingEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing or invalid "event_name"/');

        FrontendTelemetryEvent::fromArray([], self::WHITELIST);
    }

    #[Test]
    public function rejectNumericEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FrontendTelemetryEvent::fromArray(['event_name' => 123], self::WHITELIST);
    }

    // ------------------------------------------------------------------
    // Attributes count limit
    // ------------------------------------------------------------------

    #[Test]
    public function rejectTooManyAttributes(): void
    {
        $attributes = [];
        for ($i = 1; $i <= 31; ++$i) {
            $attributes['attr.'.$i] = 'value';
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Too many attributes: 31 \(max 30\)/');

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => $attributes,
        ], self::WHITELIST);
    }

    // ------------------------------------------------------------------
    // Attribute value length limit
    // ------------------------------------------------------------------

    #[Test]
    public function rejectAttributeValueTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too long/');

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => [
                'ui.data' => str_repeat('x', 513),
            ],
        ], self::WHITELIST);
    }

    #[Test]
    public function acceptAttributeValueExactlyMaxLength(): void
    {
        $event = FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => [
                'ui.data' => str_repeat('x', 512),
            ],
        ], self::WHITELIST);

        self::assertSame(512, mb_strlen($event->getAttributes()['ui.data']));
    }

    // ------------------------------------------------------------------
    // Attribute key format
    // ------------------------------------------------------------------

    #[Test]
    public function rejectInvalidKeyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid attribute key/');

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => [
                'Invalid-Key!' => 'value',
            ],
        ], self::WHITELIST);
    }

    #[Test]
    public function rejectKeyStartingWithDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'attributes' => [
                '.invalid' => 'value',
            ],
        ], self::WHITELIST);
    }

    // ------------------------------------------------------------------
    // client_timestamp_ms
    // ------------------------------------------------------------------

    #[Test]
    public function rejectInvalidClientTimestampType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/client_timestamp_ms.*must be a number/');

        FrontendTelemetryEvent::fromArray([
            'event_name' => 'page_view',
            'client_timestamp_ms' => 'not-a-number',
        ], self::WHITELIST);
    }
}
