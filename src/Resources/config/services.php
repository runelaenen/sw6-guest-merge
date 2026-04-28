<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Laenen\GuestMerge\Service\TokenGenerator;
use Laenen\GuestMerge\Service\SystemConfigReader;
use Laenen\GuestMerge\Service\GuestOrderFinder;
use Laenen\GuestMerge\Service\MergeRequestService;
use Laenen\GuestMerge\Service\CustomerAggregateRecalculator;
use Laenen\GuestMerge\Service\GuestOrderMerger;
use Laenen\GuestMerge\Service\MergeNotificationMailer;
use Laenen\GuestMerge\Subscriber\CustomerRegisterSubscriber;
use Laenen\GuestMerge\Controller\Admin\MergeAdminController;
use Laenen\GuestMerge\Controller\Storefront\MergeConfirmController;
use Laenen\GuestMerge\Core\SalesChannel\GuestMerge\GuestMergeRoute;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // ============== Services ==============

    $services->set(TokenGenerator::class);

    $services->set(SystemConfigReader::class)
        ->arg('$config', service('Shopware\Core\System\SystemConfig\SystemConfigService'));

    $services->set(GuestOrderFinder::class)
        ->arg('$connection', service('Doctrine\DBAL\Connection'))
        ->arg('$config', service(SystemConfigReader::class));

    $services->set(MergeRequestService::class)
        ->arg('$connection', service('Doctrine\DBAL\Connection'))
        ->arg('$tokenGenerator', service(TokenGenerator::class))
        ->arg('$finder', service(GuestOrderFinder::class))
        ->arg('$config', service(SystemConfigReader::class))
        ->arg('$eventDispatcher', service('event_dispatcher'));

    $services->set(CustomerAggregateRecalculator::class)
        ->arg('$connection', service('Doctrine\DBAL\Connection'))
        ->arg('$customerRepository', service('customer.repository'));

    $services->set(GuestOrderMerger::class)
        ->arg('$connection', service('Doctrine\DBAL\Connection'))
        ->arg('$finder', service(GuestOrderFinder::class))
        ->arg('$aggregateRecalculator', service(CustomerAggregateRecalculator::class))
        ->arg('$requestService', service(MergeRequestService::class))
        ->arg('$customerRepository', service('customer.repository'))
        ->arg('$eventDispatcher', service('event_dispatcher'))
        ->arg('$logger', service('logger'));

    $services->set(MergeNotificationMailer::class)
        ->arg('$mailService', service('Shopware\Core\Content\Mail\Service\MailService'))
        ->arg('$mailTemplateRepository', service('mail_template.repository'))
        ->arg('$customerRepository', service('customer.repository'))
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->arg('$connection', service('Doctrine\DBAL\Connection'))
        ->arg('$config', service(SystemConfigReader::class))
        ->arg('$logger', service('logger'));

    // ============== Subscribers ==============

    $services->set(CustomerRegisterSubscriber::class)
        ->arg('$finder', service(GuestOrderFinder::class))
        ->arg('$requestStack', service('request_stack'))
        ->arg('$translator', service('translator'))
        ->arg('$config', service(SystemConfigReader::class))
        ->tag('kernel.event_subscriber');

    // ============== Store API Routes ==============

    $services->set(GuestMergeRoute::class)
        ->public()
        ->arg('$requestService', service(MergeRequestService::class))
        ->arg('$finder', service(GuestOrderFinder::class))
        ->arg('$merger', service(GuestOrderMerger::class))
        ->arg('$mailer', service(MergeNotificationMailer::class))
        ->arg('$config', service(SystemConfigReader::class));

    // ============== Controllers ==============

    $services->set(MergeAdminController::class)
        ->public()
        ->arg('$finder', service(GuestOrderFinder::class))
        ->arg('$requestService', service(MergeRequestService::class))
        ->arg('$merger', service(GuestOrderMerger::class))
        ->arg('$mailer', service(MergeNotificationMailer::class))
        ->arg('$config', service(SystemConfigReader::class));

    $services->set(MergeConfirmController::class)
        ->public()
        ->arg('$route', service(GuestMergeRoute::class));
};