<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\LogRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LogRedactorTest extends TestCase
{
    #[Test]
    public function masksCredentialsAtAnyDepthAndKeepsTheRest(): void
    {
        $redacted = LogRedactor::redact([
            'DOMAIN' => 'example.bitrix24.ru',
            'AUTH_ID' => 'secret-1',
            'REFRESH_ID' => 'secret-2',
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'access_token' => 'secret-3',
                'refresh_token' => 'secret-4',
                'application_token' => 'secret-5',
                'member_id' => 'member-1',
            ],
        ]);

        $this->assertSame([
            'DOMAIN' => 'example.bitrix24.ru',
            'AUTH_ID' => '***',
            'REFRESH_ID' => '***',
            'event' => 'ONAPPINSTALL',
            'auth' => [
                'access_token' => '***',
                'refresh_token' => '***',
                'application_token' => '***',
                'member_id' => 'member-1',
            ],
        ], $redacted);
    }
}
