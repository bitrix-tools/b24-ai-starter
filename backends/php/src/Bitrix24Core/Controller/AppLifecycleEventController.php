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

use App\Service\Telemetry\TelemetryInterface;
use Bitrix24\Lib\ApplicationInstallations;
use Bitrix24\Lib\Bitrix24Accounts\ValueObjects\Domain;
use Bitrix24\SDK\Application\ApplicationStatus;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationUninstall\OnApplicationUninstall;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Services\RemoteEventsFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AppLifecycleEventController
{
    public function __construct(
        private ApplicationInstallations\UseCase\OnAppInstall\Handler $onAppInstallHandler,
        private RemoteEventsFactory $remoteEventsFactory,
        private LoggerInterface $logger,
        private TelemetryInterface $telemetry,
    ) {
    }

    #[Route('/api/app-events/', name: 'b24_events', methods: ['POST'])]
    public function process(Request $incomingRequest): Response
    {
        $this->logger->debug('AppLifecycleEventController.process.start', [
            'request' => $incomingRequest->request->all(),
            'baseUrl' => $incomingRequest->getBaseUrl(),
        ]);

        try {
            // check is this request are valid bitrix24 event request?
            if (!RemoteEventsFactory::isCanProcess($incomingRequest)) {
                $this->logger->error('AppLifecycleEventController.process.unknownRequest', [
                    'request' => $incomingRequest->request->all(),
                ]);

                throw new InvalidArgumentException('AppLifecycleEventController controller can process only install or uninstall event requests from bitrix24');
            }

            // for lifecycle event OnApplicationInstall we dont have stored application token
            $b24Event = $this->remoteEventsFactory->createEvent($incomingRequest, null);
            switch ($b24Event->getEventCode()) {
                case OnApplicationInstall::CODE:
                    // finish installation process
                    // store application token
                    $this->onAppInstallHandler->handle(
                        new ApplicationInstallations\UseCase\OnAppInstall\Command(
                            $b24Event->getAuth()->member_id,
                            new Domain($b24Event->getAuth()->domain),
                            $b24Event->getAuth()->application_token,
                            // todo fix command arguments, see https://github.com/mesilov/bitrix24-php-lib/issues/64
                            new ApplicationStatus('L'),
                        ),
                    );

                    // Telemetry: installation finalized (application token received)
                    $this->telemetry->trackEvent('app_install_finalized', [
                        'portal.member_id' => $b24Event->getAuth()->member_id,
                        'portal.domain' => $b24Event->getAuth()->domain,
                    ]);

                    break;
                case OnApplicationUninstall::CODE:
                    $this->logger->debug('AppLifecycleEventController.process.uninstall', [
                        'status' => 'processed',
                    ]);

                    // Telemetry: app uninstalled
                    $this->telemetry->trackEvent('app_uninstalled', [
                        'portal.member_id' => $b24Event->getAuth()->member_id,
                        'portal.domain' => $b24Event->getAuth()->domain,
                    ]);

                    break;
                default:
                    $this->logger->warning('AppLifecycleEventController.process.unknownEvent', [
                        'code' => $b24Event->getEventCode(),
                    ]);

                    break;
            }

            $response = new Response('OK', 200);
            $this->logger->debug('AppLifecycleEventController.process.finish', [
                'response' => $response->getContent(),
                'statusCode' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (\Throwable $throwable) {
            $this->logger->error('AppLifecycleEventController.error', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return new Response(sprintf('error on bitrix24 event processing: %s', $throwable->getMessage()), 500);
        }
    }
}
