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

namespace App\Bitrix24Core\Controller;

use App\Bitrix24Core\Bitrix24ServiceBuilderFactory;
use App\Bitrix24Core\FrontendPayload;
use App\Service\Telemetry\TelemetryInterface;
use Bitrix24\Lib\ApplicationInstallations;
use Bitrix24\Lib\Bitrix24Accounts\ValueObjects\Domain;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationUninstall\OnApplicationUninstall;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Services\Main\Common\EventHandlerMetadata;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppLifecycleController extends AbstractController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ApplicationInstallations\UseCase\Install\Handler $installStartHandler,
        private readonly Bitrix24ServiceBuilderFactory $bitrix24ServiceBuilderFactory,
        private readonly LoggerInterface $logger,
        private readonly TelemetryInterface $telemetry,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    #[Route('/api/install', name: 'b24_install', methods: ['POST', 'OPTIONS'])]
    public function install(Request $request): Response
    {
        $this->logger->debug('AppLifecycleController.install.start', [
            'request' => $request->request->all(),
            'baseUrl' => $request->getBaseUrl(),
        ]);

        $content = $request->getContent();
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!$payload) {
            throw new InvalidArgumentException('Invalid JSON payload');
        }

        $this->logger->debug('AppLifecycleController.install.payload', [
            'payload' => print_r($payload, true),
        ]);
        $frontendPayload = FrontendPayload::initFromArray($payload);

        $this->logger->debug('AppLifecycleController.install.frontendPayload', [
            'payload' => print_r($frontendPayload, true),
        ]);

        try {
            // now we receive OnApplicationInstall event from Bitrix24
            $b24ServiceBuilder = $this->bitrix24ServiceBuilderFactory->createFromFrontendPayload($frontendPayload);

            // get information about portal
            $b24CurrentUserProfile = $b24ServiceBuilder->getMainScope()->main()->getCurrentUserProfile()->getUserProfile();
            $b24ApplicationInfo = $b24ServiceBuilder->getMainScope()->main()->getApplicationInfo()->applicationInfo();
            $b24ApplicationStatus = $b24ApplicationInfo->getStatus();
            $b24PortalLicenseFamily = $b24ApplicationInfo->LICENSE_FAMILY;
            $b24PortalUsersCount = $b24ServiceBuilder->getUserScope()->user()->countByFilter([]);

            // You can add additional information into installation fact
            $contactPersonId = null;
            $partnerContactPersonId = null;
            $partnerId = null;
            $externalId = null;
            $comment = null;

            // Telemetry: track successful app installation
            $this->telemetry->trackEvent('app_installed', [
                'app.version' => (string) $b24ApplicationInfo->VERSION,
                'app.status' => $b24ApplicationStatus->getStatusCode(),
                'portal.license_family' => $b24PortalLicenseFamily,
                'portal.users_count' => (string) $b24PortalUsersCount,
                'portal.member_id' => $frontendPayload->memberId,
                'portal.domain' => $frontendPayload->domain,
                'installer.user_id' => (string) $b24CurrentUserProfile->ID,
                'installer.is_admin' => $b24CurrentUserProfile->ADMIN ? 'true' : 'false',
            ]);

            // step 1
            // save auth tokens into database
            $this->installStartHandler->handle(
                new ApplicationInstallations\UseCase\Install\Command(
                    $frontendPayload->memberId,
                    new Domain($frontendPayload->domain),
                    $frontendPayload->authToken,
                    (int) $b24ApplicationInfo->VERSION,
                    $this->bitrix24ServiceBuilderFactory->getApplicationProfile()->scope,
                    $b24CurrentUserProfile->ID,
                    $b24CurrentUserProfile->ADMIN,
                    $b24ApplicationStatus,
                    $b24PortalLicenseFamily,
                    // in the first install step we don't have an application token
                    null,
                    $b24PortalUsersCount,
                    $contactPersonId,
                    $partnerContactPersonId,
                    $partnerId,
                    $externalId,
                    $comment,
                ),
            );

            // step 2
            // register application lifecycle event handlers

            // unbind all event handlers
            $b24ServiceBuilder->getMainScope()->eventManager()->unbindAllEventHandlers();

            // generate event handler url for application lifecycle events
            $eventHandlerUrl = sprintf('%s/api/app-events/', $this->parameterBag->get('APPLICATION_HOST'));
            $this->logger->debug('LocalAppLifecycleController.installWithoutUi.startBindEventHandlers', [
                'eventHandlerUrl' => $eventHandlerUrl,
            ]);
            $b24ServiceBuilder->getMainScope()->eventManager()->bindEventHandlers(
                [
                    // register event handlers for implemented in SDK events
                    new EventHandlerMetadata(
                        OnApplicationInstall::CODE,
                        $eventHandlerUrl,
                        $b24CurrentUserProfile->ID,
                    ),
                    new EventHandlerMetadata(
                        OnApplicationUninstall::CODE,
                        $eventHandlerUrl,
                        $b24CurrentUserProfile->ID,
                    ),
                ],
            );
            $this->logger->debug('InstallController.process.finishBindEventHandlers');

            // Telemetry: track event handler registration
            $this->telemetry->trackEvent('event_subscription_registered', [
                'portal.member_id' => $frontendPayload->memberId,
                'portal.domain' => $frontendPayload->domain,
                'registration.handler_url' => $eventHandlerUrl,
                'registration.events_count' => '2',
            ]);

            $response = new Response('OK', 200);
            $this->logger->debug('AppLifecycleController.install.finish', [
                'response' => $response->getContent(),
                'statusCode' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (\Throwable $throwable) {
            $this->logger->error('AppLifecycleController.install.error', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            // Telemetry: track installation failure
            $this->telemetry->trackError($throwable, [
                'error.category' => 'app_install_failed',
                'portal.member_id' => $frontendPayload->memberId ?? 'unknown',
                'portal.domain' => $frontendPayload->domain ?? 'unknown',
            ]);

            return new Response(sprintf('error on placement request processing: %s', $throwable->getMessage()), 500);
        }
    }
}
