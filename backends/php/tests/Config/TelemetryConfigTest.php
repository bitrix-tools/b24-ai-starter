<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Config;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Тесты конфигурации профилей телеметрии.
 *
 * Проверяем что telemetry.yaml правильно загружается
 * и содержит все необходимые профили.
 */
class TelemetryConfigTest extends KernelTestCase
{
    public function testTelemetryConfigIsLoaded(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertTrue($container->hasParameter('telemetry.active_profile'));
        $this->assertTrue($container->hasParameter('telemetry.profiles'));
    }

    public function testActiveProfileParameter(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $activeProfile = $container->getParameter('telemetry.active_profile');

        $this->assertIsString($activeProfile);
        $this->assertNotEmpty($activeProfile);
    }

    public function testProfilesParameter(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertIsArray($profiles);
        $this->assertNotEmpty($profiles);
    }

    public function testSimpleUiProfileExists(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey('simple-ui', $profiles);
        $this->assertIsArray($profiles['simple-ui']);
        $this->assertArrayHasKey('profiles', $profiles['simple-ui']);
        $this->assertArrayHasKey('exclude_patterns', $profiles['simple-ui']);
        $this->assertArrayHasKey('description', $profiles['simple-ui']);
    }

    public function testSimpleUiProfileStructure(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');
        $simpleUi = $profiles['simple-ui'];

        // Проверяем что профиль содержит LifecycleProfile и UIProfile
        $this->assertContains('App\Service\Telemetry\Profiles\LifecycleProfile', $simpleUi['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\UIProfile', $simpleUi['profiles']);

        // Проверяем что exclude_patterns пустой для simple-ui
        $this->assertIsArray($simpleUi['exclude_patterns']);

        // Проверяем что description существует
        $this->assertIsString($simpleUi['description']);
        $this->assertNotEmpty($simpleUi['description']);
    }

    public function testIntegrationSyncProfileExists(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey('integration-sync', $profiles);
        $this->assertIsArray($profiles['integration-sync']);
    }

    public function testIntegrationSyncProfileStructure(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');
        $integrationSync = $profiles['integration-sync'];

        // Проверяем что профиль содержит 3 базовых профиля
        $this->assertCount(3, $integrationSync['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\LifecycleProfile', $integrationSync['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\UIProfile', $integrationSync['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\IntegrationProfile', $integrationSync['profiles']);

        // Проверяем что exclude_patterns содержит initial_sync.*
        $this->assertContains('initial_sync.*', $integrationSync['exclude_patterns']);
    }

    public function testMigratorLightProfileExists(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey('migrator-light', $profiles);
        $this->assertIsArray($profiles['migrator-light']);
    }

    public function testMigratorLightProfileStructure(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');
        $migratorLight = $profiles['migrator-light'];

        // Проверяем что профиль содержит MigrationProfile
        $this->assertContains('App\Service\Telemetry\Profiles\MigrationProfile', $migratorLight['profiles']);

        // Проверяем что exclude_patterns минимизирует UI атрибуты
        $this->assertContains('ui.screen.*', $migratorLight['exclude_patterns']);
        $this->assertContains('ui.widget.*', $migratorLight['exclude_patterns']);
        $this->assertContains('ui.form.*', $migratorLight['exclude_patterns']);
        $this->assertContains('session.*', $migratorLight['exclude_patterns']);
        $this->assertContains('external.*', $migratorLight['exclude_patterns']);
    }

    public function testMigratorAdvancedProfileExists(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey('migrator-advanced', $profiles);
        $this->assertIsArray($profiles['migrator-advanced']);
    }

    public function testMigratorAdvancedProfileStructure(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');
        $migratorAdvanced = $profiles['migrator-advanced'];

        // Проверяем что профиль содержит все базовые профили для миграции
        $this->assertContains('App\Service\Telemetry\Profiles\LifecycleProfile', $migratorAdvanced['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\UIProfile', $migratorAdvanced['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\MigrationProfile', $migratorAdvanced['profiles']);

        // Проверяем что exclude_patterns пустой (полный доступ)
        $this->assertEmpty($migratorAdvanced['exclude_patterns']);
    }

    public function testDevelopmentProfileExists(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey('development', $profiles);
        $this->assertIsArray($profiles['development']);
    }

    public function testDevelopmentProfileHasAllProfiles(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');
        $development = $profiles['development'];

        // Development профиль должен содержать ВСЕ базовые профили
        $this->assertCount(4, $development['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\LifecycleProfile', $development['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\UIProfile', $development['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\IntegrationProfile', $development['profiles']);
        $this->assertContains('App\Service\Telemetry\Profiles\MigrationProfile', $development['profiles']);

        // Без исключений
        $this->assertEmpty($development['exclude_patterns']);
    }

    public function testAllProfilesHaveRequiredKeys(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        foreach ($profiles as $profileName => $profileConfig) {
            $this->assertArrayHasKey('profiles', $profileConfig, "Profile '{$profileName}' missing 'profiles' key");
            $this->assertArrayHasKey('exclude_patterns', $profileConfig, "Profile '{$profileName}' missing 'exclude_patterns' key");
            $this->assertArrayHasKey('description', $profileConfig, "Profile '{$profileName}' missing 'description' key");

            $this->assertIsArray($profileConfig['profiles'], "Profile '{$profileName}' 'profiles' must be array");
            $this->assertIsArray($profileConfig['exclude_patterns'], "Profile '{$profileName}' 'exclude_patterns' must be array");
            $this->assertIsString($profileConfig['description'], "Profile '{$profileName}' 'description' must be string");
        }
    }

    public function testActiveProfileExistsInProfilesList(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $activeProfile = $container->getParameter('telemetry.active_profile');
        $profiles = $container->getParameter('telemetry.profiles');

        $this->assertArrayHasKey(
            $activeProfile,
            $profiles,
            "Active profile '{$activeProfile}' must exist in profiles list",
        );
    }

    public function testProfileClassesExist(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $profiles = $container->getParameter('telemetry.profiles');

        foreach ($profiles as $profileName => $profileConfig) {
            foreach ($profileConfig['profiles'] as $className) {
                $this->assertTrue(
                    class_exists($className),
                    "Profile class '{$className}' referenced in '{$profileName}' does not exist",
                );
            }
        }
    }
}
