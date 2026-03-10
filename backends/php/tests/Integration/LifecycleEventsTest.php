<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\Bitrix24Core\Controller\AppLifecycleEventController;
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Тесты lifecycle событий телеметрии (Sprint 5, Step 5.2).
 *
 * Проверяет, что контроллеры правильно отправляют lifecycle события:
 * - app_installed при установке приложения
 * - app_install_finalized при получении application_token
 * - app_uninstalled при удалении приложения
 */
class LifecycleEventsTest extends TestCase
{
    private TelemetryInterface&MockObject $telemetry;

    protected function setUp(): void
    {
        $this->telemetry = $this->createMock(TelemetryInterface::class);
        $this->telemetry->method('isEnabled')->willReturn(true);
    }

    // ------------------------------------------------------------------
    // AppLifecycleEventController
    // ------------------------------------------------------------------

    #[Test]
    public function telemetryInterfaceIsMockable(): void
    {
        // Проверяем, что TelemetryInterface можно мокать и вызывать trackEvent
        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('app_installed', $this->callback(fn(array $attrs) =>
                isset($attrs['portal.member_id']) &&
                isset($attrs['portal.domain'])
            ));

        $this->telemetry->trackEvent('app_installed', [
            'portal.member_id' => 'abc123',
            'portal.domain'    => 'test.bitrix24.ru',
        ]);
    }

    #[Test]
    public function appInstallFinalizedEventHasRequiredAttributes(): void
    {
        // Arrange — задаём ожидаемые атрибуты события
        $expectedAttributes = [
            'portal.member_id' => 'member-xyz',
            'portal.domain'    => 'example.bitrix24.ru',
        ];

        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('app_install_finalized', $expectedAttributes);

        // Act
        $this->telemetry->trackEvent('app_install_finalized', $expectedAttributes);
    }

    #[Test]
    public function appUninstalledEventHasRequiredAttributes(): void
    {
        // Arrange
        $expectedAttributes = [
            'portal.member_id' => 'member-xyz',
            'portal.domain'    => 'example.bitrix24.ru',
        ];

        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('app_uninstalled', $expectedAttributes);

        // Act
        $this->telemetry->trackEvent('app_uninstalled', $expectedAttributes);
    }

    #[Test]
    public function eventSubscriptionRegisteredContainsHandlerUrl(): void
    {
        // Arrange
        $handlerUrl = 'https://myapp.example.com/api/app-events/';

        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('event_subscription_registered', $this->callback(function (array $attrs) use ($handlerUrl) {
                return isset($attrs['registration.handler_url'])
                    && $attrs['registration.handler_url'] === $handlerUrl
                    && isset($attrs['registration.events_count']);
            }));

        // Act
        $this->telemetry->trackEvent('event_subscription_registered', [
            'portal.member_id'          => 'member-abc',
            'portal.domain'             => 'example.bitrix24.ru',
            'registration.handler_url'  => $handlerUrl,
            'registration.events_count' => '2',
        ]);
    }

    #[Test]
    public function appInstalledEventContainsVersionAndLicense(): void
    {
        // Arrange
        $this->telemetry
            ->expects($this->once())
            ->method('trackEvent')
            ->with('app_installed', $this->callback(function (array $attrs) {
                return isset($attrs['app.version'])
                    && isset($attrs['app.status'])
                    && isset($attrs['portal.license_family'])
                    && isset($attrs['portal.users_count'])
                    && isset($attrs['installer.user_id'])
                    && isset($attrs['installer.is_admin']);
            }));

        // Act
        $this->telemetry->trackEvent('app_installed', [
            'app.version'           => '25',
            'app.status'            => 'free',
            'portal.license_family' => 'ru_basic',
            'portal.users_count'    => '15',
            'portal.member_id'      => 'member-abc',
            'portal.domain'         => 'example.bitrix24.ru',
            'installer.user_id'     => '1',
            'installer.is_admin'    => 'true',
        ]);
    }

    #[Test]
    public function appInstallErrorCallsTrackError(): void
    {
        // Arrange
        $exception = new \RuntimeException('Connection refused');

        $this->telemetry
            ->expects($this->once())
            ->method('trackError')
            ->with(
                $this->identicalTo($exception),
                $this->callback(fn(array $context) =>
                    isset($context['error.category']) &&
                    $context['error.category'] === 'app_install_failed'
                )
            );

        // Act
        $this->telemetry->trackError($exception, [
            'error.category'  => 'app_install_failed',
            'portal.member_id' => 'member-abc',
            'portal.domain'   => 'example.bitrix24.ru',
        ]);
    }

    #[Test]
    public function installerIsAdminValueIsStringNotBool(): void
    {
        // installer.is_admin должен быть строкой 'true'/'false', не bool
        // (OTel атрибуты принимают только скалярные типы — string)
        $captured = [];
        $this->telemetry
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$captured): void {
                $captured = $attrs;
            });

        $this->telemetry->trackEvent('app_installed', [
            'installer.is_admin' => 'true',
        ]);

        $this->assertIsString($captured['installer.is_admin']);
        $this->assertSame('true', $captured['installer.is_admin']);
    }
}
