<?php

declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

/**
 * JWT Service for token generation and validation.
 *
 * This service provides stateless JWT authentication using HMAC (HS256) algorithm.
 * Tokens contain Bitrix24 domain information and have a configurable TTL.
 */
class JwtService
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
        private readonly string $algorithm,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate JWT token with Bitrix24 domain information.
     *
     * @param string      $domain      Bitrix24 domain (e.g., "company.bitrix24.com")
     * @param string|null $memberId    Bitrix24 member ID (optional)
     * @param string|null $accessToken Bitrix24 OAuth access token (optional)
     *
     * @return string JWT token
     */
    public function generateToken(string $domain, ?string $memberId = null, ?string $accessToken = null): string
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->ttl;

        $payload = [
            'iss' => 'bitrix24-app',           // Issuer
            'iat' => $issuedAt,                 // Issued at
            'exp' => $expiresAt,                // Expires at
            'domain' => $domain,                // Bitrix24 domain
            'jti' => $this->generateJti(),      // Unique token ID
        ];

        // Add member_id if provided
        if (null !== $memberId) {
            $payload['member_id'] = $memberId;
        }

        // Add access_token if provided
        if (null !== $accessToken) {
            $payload['access_token'] = $accessToken;
        }

        $this->logger->debug('Generating JWT token', [
            'domain' => $domain,
            'member_id' => $memberId,
            'has_access_token' => null !== $accessToken,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Validate JWT token signature and expiration.
     *
     * @param string $token JWT token to validate
     *
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token): bool
    {
        try {
            JWT::decode($token, new Key($this->secret, $this->algorithm));

            $this->logger->debug('JWT token validated successfully');

            return true;
        } catch (\Exception $exception) {
            $this->logger->warning('JWT token validation failed', [
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            return false;
        }
    }

    /**
     * Decode JWT token and return payload.
     *
     * @param string $token JWT token to decode
     *
     * @return array<string, mixed>|null Decoded payload or null if invalid
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            // Convert stdClass to array
            $payload = json_decode(json_encode($decoded), true);

            $this->logger->debug('JWT token decoded successfully', [
                'domain' => $payload['domain'] ?? 'unknown',
                'member_id' => $payload['member_id'] ?? null,
            ]);

            return $payload;
        } catch (\Exception $exception) {
            $this->logger->warning('JWT token decoding failed', [
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            return null;
        }
    }

    /**
     * Generate unique JWT ID (jti claim).
     *
     * @return string Unique identifier
     */
    private function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get configured TTL in seconds.
     *
     * @return int Time to live in seconds
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
