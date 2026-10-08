<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Bitrix24Core\Bitrix24ServiceBuilderFactory;
use App\Bitrix24Core\FrontendAuthException;
use App\Bitrix24Core\FrontendAuthVerifier;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Entity\Bitrix24AccountInterface;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Entity\Bitrix24AccountStatus;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Repository\Bitrix24AccountRepositoryInterface;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `/api/getToken` must issue a JWT only when the Bitrix24 OAuth server confirms
 * the caller's token belongs to THIS app on the claimed portal, and the app is
 * installed on that portal.
 */
class FrontendAuthVerifierTest extends TestCase
{
    private const string CLIENT_ID = 'local.abc123.456';

    private const string DOMAIN = 'example.bitrix24.ru';

    private const string MEMBER_ID = 'member-1';

    #[Test]
    #[DataProvider('incompletePayloads')]
    public function rejectsIncompletePayloadWithoutCallingTheOAuthServer(array $payload): void
    {
        $verifier = $this->verifier([], new MockHttpClient(static fn () => self::fail('OAuth server must not be called')));

        $this->expectException(FrontendAuthException::class);
        $this->expectExceptionCode(400);

        $verifier->verify($payload);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompletePayloads(): iterable
    {
        yield 'no domain' => [['member_id' => self::MEMBER_ID, 'AUTH_ID' => 'token']];
        yield 'no member_id' => [['DOMAIN' => self::DOMAIN, 'AUTH_ID' => 'token']];
        yield 'no access token' => [['DOMAIN' => self::DOMAIN, 'member_id' => self::MEMBER_ID]];
        yield 'domain is not a string' => [['DOMAIN' => ['x'], 'member_id' => self::MEMBER_ID, 'AUTH_ID' => 'token']];
    }

    #[Test]
    public function asksTheOAuthServerAndNeverSendsTheRefreshToken(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = [$method, $url];

            return self::appInfo();
        });

        $this->verifier([$this->account(self::DOMAIN, Bitrix24AccountStatus::active)], $http)
            ->verify(self::payload(['REFRESH_ID' => 'refresh-secret']));

        $this->assertCount(1, $requested);
        $this->assertSame('GET', $requested[0][0]);
        $this->assertStringStartsWith(FrontendAuthVerifier::oauthServers()[0].'rest/app.info/', $requested[0][1]);
        $this->assertStringContainsString('auth=valid-token', $requested[0][1]);
        $this->assertStringNotContainsString('refresh-secret', $requested[0][1]);
    }

    #[Test]
    #[DataProvider('mismatchingAppInfo')]
    public function rejectsTokenTheOAuthServerDoesNotConfirm(MockResponse $response): void
    {
        // the same answer from every OAuth server (configured + other region)
        $responses = array_map(static fn (): MockResponse => clone $response, FrontendAuthVerifier::oauthServers());
        $verifier = $this->verifier([$this->account(self::DOMAIN, Bitrix24AccountStatus::active)], new MockHttpClient($responses));

        $this->expectException(FrontendAuthException::class);
        $this->expectExceptionCode(401);

        $verifier->verify(self::payload());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function mismatchingAppInfo(): iterable
    {
        yield 'expired or fake token' => [new MockResponse('{"error":"expired_token"}', ['http_code' => 401])];
        yield 'token of another application' => [self::appInfo(clientId: 'local.other.app')];
        yield 'token of another portal' => [self::appInfo(domain: 'attacker.bitrix24.ru')];
        yield 'member_id of another portal' => [self::appInfo(memberId: 'member-2')];
        yield 'app not installed' => [self::appInfo(installed: false)];
        yield 'not json' => [new MockResponse('<html>')];
    }

    #[Test]
    public function triesTheOtherRegionWhenTheConfiguredServerDoesNotKnowTheToken(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return 1 === count($urls) ? new MockResponse('{"error":"invalid_token"}', ['http_code' => 401]) : self::appInfo();
        });
        $account = $this->account(self::DOMAIN, Bitrix24AccountStatus::active);

        $this->assertSame($account, $this->verifier([$account], $http)->verify(self::payload()));
        $this->assertCount(2, $urls);
        $this->assertNotSame(parse_url($urls[0], PHP_URL_HOST), parse_url($urls[1], PHP_URL_HOST));
    }

    #[Test]
    public function reportsUnavailableWhenNoOAuthServerCanBeReached(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Could not resolve host']));
        $verifier = $this->verifier([$this->account(self::DOMAIN, Bitrix24AccountStatus::active)], $http);

        $this->expectException(FrontendAuthException::class);
        $this->expectExceptionCode(503);

        $verifier->verify(self::payload());
    }

    #[Test]
    public function rejectsPortalWhereAppIsNotInstalledWithoutCallingTheOAuthServer(): void
    {
        $verifier = $this->verifier([], new MockHttpClient(static fn () => self::fail('OAuth server must not be called')));

        $this->expectException(FrontendAuthException::class);
        $this->expectExceptionCode(401);

        $verifier->verify(self::payload());
    }

    #[Test]
    #[DataProvider('inactiveStatuses')]
    public function rejectsInactiveAccount(Bitrix24AccountStatus $status): void
    {
        $verifier = $this->verifier([$this->account(self::DOMAIN, $status)], new MockHttpClient(static fn () => self::fail('OAuth server must not be called')));

        $this->expectException(FrontendAuthException::class);
        $this->expectExceptionCode(401);

        $verifier->verify(self::payload());
    }

    /**
     * @return iterable<string, array{Bitrix24AccountStatus}>
     */
    public static function inactiveStatuses(): iterable
    {
        yield 'deleted' => [Bitrix24AccountStatus::deleted];
        yield 'blocked' => [Bitrix24AccountStatus::blocked];
    }

    #[Test]
    #[DataProvider('installedStatuses')]
    public function acceptsConfirmedTokenOnInstalledPortal(Bitrix24AccountStatus $status): void
    {
        $account = $this->account(self::DOMAIN, $status);
        $verifier = $this->verifier([$account], new MockHttpClient(self::appInfo()));

        $this->assertSame($account, $verifier->verify(self::payload(['DOMAIN' => 'https://Example.Bitrix24.ru/'])));
    }

    /**
     * @return iterable<string, array{Bitrix24AccountStatus}>
     */
    public static function installedStatuses(): iterable
    {
        yield 'active' => [Bitrix24AccountStatus::active];
        yield 'still finishing installation' => [Bitrix24AccountStatus::new];
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private static function payload(array $override = []): array
    {
        return array_merge([
            'DOMAIN' => self::DOMAIN,
            'member_id' => self::MEMBER_ID,
            'AUTH_ID' => 'valid-token',
        ], $override);
    }

    private static function appInfo(
        string $clientId = self::CLIENT_ID,
        string $domain = self::DOMAIN,
        string $memberId = self::MEMBER_ID,
        bool $installed = true,
    ): MockResponse {
        return new MockResponse((string) json_encode(['result' => [
            'client_id' => $clientId,
            'user_id' => 1,
            'install' => ['domain' => $domain, 'member_id' => $memberId, 'installed' => $installed],
        ]]));
    }

    /**
     * @param Bitrix24AccountInterface[] $accounts
     */
    private function verifier(array $accounts, MockHttpClient $httpClient): FrontendAuthVerifier
    {
        $repository = $this->createMock(Bitrix24AccountRepositoryInterface::class);
        $repository->method('findByMemberId')->willReturn($accounts);

        $factory = $this->createMock(Bitrix24ServiceBuilderFactory::class);
        $factory->method('getApplicationProfile')->willReturn(ApplicationProfile::initFromArray([
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => self::CLIENT_ID,
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => 'secret',
            'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => 'crm',
        ]));

        return new FrontendAuthVerifier($repository, $factory, $httpClient);
    }

    private function account(string $domain, Bitrix24AccountStatus $status): Bitrix24AccountInterface
    {
        $account = $this->createMock(Bitrix24AccountInterface::class);
        $account->method('getDomainUrl')->willReturn($domain);
        $account->method('getStatus')->willReturn($status);
        $account->method('getMemberId')->willReturn(self::MEMBER_ID);

        return $account;
    }
}
