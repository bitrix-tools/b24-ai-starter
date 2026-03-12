<?php

/**
 * This file is part of the b24sdk examples package.
 *
 * © Maksim Mesilov <mesilov.maxim@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Bitrix24Core;

use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Entity\Bitrix24AccountStatus;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Exceptions\Bitrix24AccountNotFoundException;
use Bitrix24\SDK\Application\Contracts\Bitrix24Accounts\Repository\Bitrix24AccountRepositoryInterface;
use Bitrix24\SDK\Core\Contracts\Events\EventInterface;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\DefaultOAuthServerUrl;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Core\Exceptions\UnknownScopeCodeException;
use Bitrix24\SDK\Core\Exceptions\WrongConfigurationException;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

readonly class Bitrix24ServiceBuilderFactory
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ParameterBagInterface $parameterBag,
        private Bitrix24AccountRepositoryInterface $bitrix24AccountRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createFromFrontendPayload(FrontendPayload $frontendPayload): ServiceBuilder
    {
        return (new ServiceBuilderFactory($this->eventDispatcher, $this->logger))->init(
            $this->getApplicationProfile(),
            $frontendPayload->authToken,
            $frontendPayload->domain,
            DefaultOAuthServerUrl::default(),
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws WrongConfigurationException
     */
    public function createFromIncomingEvent(EventInterface $b24Event): ServiceBuilder
    {
        return (new ServiceBuilderFactory($this->eventDispatcher, $this->logger))->init(
            $this->getApplicationProfile(),
            $b24Event->getAuth()->authToken,
            $b24Event->getAuth()->domain,
            DefaultOAuthServerUrl::default(),
        );
    }

    /**
     * @throws WrongConfigurationException
     * @throws UnknownScopeCodeException
     * @throws InvalidArgumentException
     * @throws Bitrix24AccountNotFoundException
     */
    public function createFromStoredTokenForDomain(string $b24Domain): ServiceBuilder
    {
        if ('' === $b24Domain || '0' === $b24Domain) {
            throw new InvalidArgumentException('domain is empty');
        }

        $b24Accounts = $this->bitrix24AccountRepository->findByDomain(
            $b24Domain,
            Bitrix24AccountStatus::active,
            true,
        );
        if ([] === $b24Accounts) {
            throw new Bitrix24AccountNotFoundException(sprintf('b24 account %s not found', $b24Domain));
        }

        $b24Account = $b24Accounts[0];

        return (new ServiceBuilderFactory(
            $this->eventDispatcher,
            $this->logger,
        ))->init(
            $this->getApplicationProfile(),
            // load auth tokens from a database
            $b24Account->getAuthToken(),
            $b24Account->getDomainUrl(),
            DefaultOAuthServerUrl::default(),
        );
    }

    /**
     * @throws WrongConfigurationException
     * @throws InvalidArgumentException
     * @throws UnknownScopeCodeException
     */
    public function getApplicationProfile(): ApplicationProfile
    {
        try {
            // todo add validation
            return ApplicationProfile::initFromArray([
                'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $this->parameterBag->get('bitrix24sdk.app.client_id'),
                'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $this->parameterBag->get('bitrix24sdk.app.client_secret'),
                'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $this->parameterBag->get('bitrix24sdk.app.scope'),
            ]);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->logger->error(
                'Bitrix24ServiceBuilderFactory.getApplicationProfile.error',
                [
                    'message' => sprintf('cannot read config from $_ENV: %s', $invalidArgumentException->getMessage()),
                    'trace' => $invalidArgumentException->getTraceAsString(),
                ],
            );

            throw $invalidArgumentException;
        }
    }
}
