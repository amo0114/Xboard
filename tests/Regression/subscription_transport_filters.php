<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Protocols\ClashMeta;
use App\Protocols\Loon;
use App\Support\AbstractProtocol;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

$container = new Container();
Container::setInstance($container);
$container->instance('app', $container);
Facade::setFacadeApplication($container);
$servers = [
    ['name' => 'TCP fixture', 'type' => 'vless', 'protocol_settings' => ['network' => 'tcp']],
    ['name' => 'XHTTP fixture', 'type' => 'vless', 'protocol_settings' => ['network' => 'xhttp']],
];
$cases = [
    [new Loon([], $servers, 'loon', '3.5.2'), ['TCP fixture']],
    [new ClashMeta([], $servers, 'meta', '1.19.21'), ['TCP fixture']],
    [new ClashMeta([], $servers, 'meta', '1.19.22'), ['TCP fixture', 'XHTTP fixture']],
    [new ClashMeta([], $servers, 'meta', '1.19.33'), ['TCP fixture', 'XHTTP fixture']],
];
$property = new ReflectionProperty(AbstractProtocol::class, 'servers');
foreach ($cases as [$protocol, $expected]) {
    if (array_column($property->getValue($protocol), 'name') !== $expected) {
        throw new RuntimeException('Unsupported transport leaked into the subscription');
    }
}
echo json_encode(['checks' => count($cases), 'passed' => true, 'database_used' => false]) . PHP_EOL;
