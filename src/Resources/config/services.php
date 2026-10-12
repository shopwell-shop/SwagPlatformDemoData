<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Shopwell\Core\Content\Media\File\FileSaver;
use Shopwell\Core\Framework\Api\Controller\SyncController;
use Swag\PlatformDemoData\DataProvider\CategoryProvider;
use Swag\PlatformDemoData\DataProvider\CmsPageProvider;
use Swag\PlatformDemoData\DataProvider\CustomerProvider;
use Swag\PlatformDemoData\DataProvider\ManufacturerProvider;
use Swag\PlatformDemoData\DataProvider\OrderProvider;
use Swag\PlatformDemoData\DataProvider\ProductStreamProvider;
use Swag\PlatformDemoData\DataProvider\PromotionProvider;
use Swag\PlatformDemoData\DataProvider\TagProvider;
use Swag\PlatformDemoData\DataProvider\MediaProvider;
use Swag\PlatformDemoData\DataProvider\ProductProvider;
use Swag\PlatformDemoData\DataProvider\PropertyProvider;
use Swag\PlatformDemoData\DataProvider\RuleProvider;
use Swag\PlatformDemoData\DataProvider\ShippingMethodProvider;
use Swag\PlatformDemoData\DataProvider\TaxProvider;
use Swag\PlatformDemoData\DemoDataService;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DemoDataService::class)
        ->public()
        ->args([
            service(SyncController::class),
            tagged_iterator('swag.demo_data.data_provider'),
            service('request_stack'),
        ]);

    $services->set(MediaProvider::class)
        ->args([
            service(Connection::class),
            service(FileSaver::class),
            service('shopwell.filesystem.public'),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 1100]);

    $services->set(CmsPageProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 1000]);

    $services->set(CategoryProvider::class)
        ->args([
            service('category.repository'),
            service(Connection::class),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 900]);

    $services->set(CustomerProvider::class)
        ->args([
            service(Connection::class),
            service('category.repository'),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 800]);

    $services->set(RuleProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 700]);

    $services->set(TaxProvider::class)
        ->args([])
        ->tag('swag.demo_data.data_provider', ['priority' => 650]);

    $services->set(PropertyProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 500]);

    $services->set(ManufacturerProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 450]);

    $services->set(ProductProvider::class)
        ->args([
            service(Connection::class),
            service(ClockInterface::class),
            service('product.repository'),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 400]);

    $services->set(ShippingMethodProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 200]);

    $services->set(TagProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 1200]);

    $services->set(ProductStreamProvider::class)
        ->args([service(Connection::class)])
        ->tag('swag.demo_data.data_provider', ['priority' => 880]);

    $services->set(PromotionProvider::class)
        ->args([
            service(Connection::class),
            service('promotion.repository'),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 690]);

    $services->set(OrderProvider::class)
        ->args([
            service(Connection::class),
            service(ClockInterface::class),
        ])
        ->tag('swag.demo_data.data_provider', ['priority' => 300]);
};
