<?php

declare(strict_types=1);

namespace App\Bitrix24Core;

use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Entity\Bitrix24AccountInterface;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Entity\Bitrix24AccountStatus;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Repository\Bitrix24AccountRepositoryInterface;
use Bitrix24\SDK\Core\Credentials\DefaultOAuthServerUrl;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proves that a frontend request to `/api/getToken` comes from a user of a portal
 * where this application is installed, before a JWT is issued for that portal.
 *
 * 1. The portal must have an installed account here (status new/active) — a
 *    cheap local check that rejects most forged requests without network calls.
 * 2. `AUTH_ID` is checked by the Bitrix24 OAuth server (`app.info`), a fixed
 *    trusted host — never the portal from the request, which the caller controls.
 *    The answer must name OUR `client_id`, the same domain and `member_id`, and
 *    an installed app. Same check as b24pysdk's `validate_placement_request`.
 *
 * Why not the SDK's `Main::guardValidateCurrentAuthToken()`: it compares only the
 * portal URL, not `client_id` / `member_id`. The server URL still comes from the
 * SDK (`DefaultOAuthServerUrl`, env `BITRIX24_PHP_SDK_DEFAULT_AUTH_SERVER_URL`);
 * if that server does not know the token, the other Bitrix24 region is tried.
 *
 * The refresh token is never sent, so verification cannot renew (and overwrite)
 * the installation's stored tokens.
 */
readonly class FrontendAuthVerifier
{
    private const array ALLOWED_STATUSES = [
        Bitrix24AccountStatus::new,
        Bitrix24AccountStatus::active,
    ];

    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private Bitrix24AccountRepositoryInterface $bitrix24AccountRepository,
        private Bitrix24ServiceBuilderFactory $bitrix24ServiceBuilderFactory,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $payload decoded body of `/api/getToken`
     *
     * @throws FrontendAuthException
     */
    public function verify(array $payload): Bitrix24AccountInterface
    {
        $domain = self::normalizeDomain(self::string($payload['DOMAIN'] ?? null));
        $memberId = self::string($payload['member_id'] ?? null);
        $accessToken = self::string($payload['AUTH_ID'] ?? null);

        if ('' === $domain || '' === $memberId || '' === $accessToken) {
            throw new FrontendAuthException('Missing required parameters: DOMAIN, member_id, AUTH_ID', 400);
        }

        $account = $this->findInstalledAccount($memberId, $domain);
        if (!$account instanceof Bitrix24AccountInterface) {
            throw new FrontendAuthException('Application is not installed on this portal', 401);
        }

        $appInfo = $this->fetchAppInfo($accessToken);
        $install = is_array($appInfo['install'] ?? null) ? $appInfo['install'] : [];

        if (
            ($appInfo['client_id'] ?? null) !== $this->bitrix24ServiceBuilderFactory->getApplicationProfile()->clientId
            || self::normalizeDomain(self::string($install['domain'] ?? null)) !== $domain
            || ($install['member_id'] ?? null) !== $memberId
            || true !== ($install['installed'] ?? null)
        ) {
            throw new FrontendAuthException('Invalid Bitrix24 credentials', 401);
        }

        return $account;
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);

        return rtrim($domain, '/');
    }

    /**
     * @return list<string> configured OAuth server first, then the other region
     */
    public static function oauthServers(): array
    {
        $servers = [DefaultOAuthServerUrl::default(), DefaultOAuthServerUrl::west(), DefaultOAuthServerUrl::east()];

        return array_values(array_unique(array_map(static fn (string $url): string => rtrim($url, '/').'/', $servers)));
    }

    /**
     * @return array<string, mixed> `result` of app.info from the first server that knows the token
     *
     * @throws FrontendAuthException 401 if no server confirms the token, 503 if none could be reached
     */
    private function fetchAppInfo(string $accessToken): array
    {
        $unreachable = null;
        foreach (self::oauthServers() as $server) {
            try {
                $data = $this->httpClient->request('GET', $server.'rest/app.info/', [
                    'query' => ['auth' => $accessToken],
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_duration' => self::TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                ])->toArray(false);
            } catch (TransportExceptionInterface $transportException) {
                $unreachable = $transportException;
                continue;
            } catch (\Throwable) {
                continue; // not JSON / unexpected answer: this server does not confirm the token
            }

            if (is_array($data['result'] ?? null)) {
                return $data['result'];
            }
        }

        if (null !== $unreachable) {
            throw new FrontendAuthException('Bitrix24 OAuth server is unavailable', 503, $unreachable);
        }

        throw new FrontendAuthException('Invalid Bitrix24 credentials', 401);
    }

    private function findInstalledAccount(string $memberId, string $domain): ?Bitrix24AccountInterface
    {
        foreach ($this->bitrix24AccountRepository->findByMemberId($memberId) as $account) {
            if (
                in_array($account->getStatus(), self::ALLOWED_STATUSES, true)
                && self::normalizeDomain($account->getDomainUrl()) === $domain
            ) {
                return $account;
            }
        }

        return null;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
