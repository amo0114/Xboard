<?php

// Database-free regression checks: php tests/Regression/reality_client_defaults.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Models\Server;
use App\Http\Requests\Admin\ServerSave;
use App\Protocols\ClashMeta;
use App\Protocols\Clash;
use App\Protocols\Loon;
use App\Support\ProtocolManager;
use App\Services\ServerService;
use Symfony\Component\Yaml\Yaml;

$checks = 0;
function expectSame($expected, $actual, string $message): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function modelWith(array $settings): Server
{
    $node = new Server();
    $node->setRawAttributes([
        'type' => 'vless', 'host' => 'node.example.com', 'port' => 443,
        'server_port' => 8443, 'protocol_settings' => json_encode($settings),
    ]);
    return $node;
}

function subscriptionNode(array $settings): array
{
    return [
        'name' => 'Reality fixture', 'type' => 'vless',
        'host' => 'node.example.com', 'port' => 443,
        'protocol_settings' => $settings,
    ];
}

$uuid = '00000000-0000-4000-8000-000000000001';
$base = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'reality_settings' => [
        'server_name' => 'www.example.com', 'public_key' => str_repeat('A', 43),
        'short_id' => '0123456789abcdef',
    ],
];
$variants = [
    $base,
    array_replace($base, ['reality_client' => null]),
    array_replace($base, ['reality_client' => []]),
    array_replace($base, ['reality_client' => [
        'support_x25519mlkem768' => null, 'loon_tls_profile' => null,
    ]]),
];
foreach ($variants as $settings) {
    $node = modelWith($settings);
    expectSame(Server::REALITY_CLIENT_DEFAULTS, $node->protocol_settings['reality_client'], 'Existing node must inherit defaults');
    $node->protocol_settings = $settings;
    $saved = json_decode($node->getAttributes()['protocol_settings'], true);
    expectSame(Server::REALITY_CLIENT_DEFAULTS, $saved['reality_client'], 'New/save path must keep defaults');
    foreach ([$settings, $node->protocol_settings] as $clientSettings) {
        $proxy = ClashMeta::buildVless($uuid, subscriptionNode($clientSettings));
        expectSame(true, data_get($proxy, 'reality-opts.support-x25519mlkem768'), 'Mihomo must enable MLKEM without node overrides');
        expectSame('chrome', $proxy['client-fingerprint'], 'Reality needs a default uTLS fingerprint');
        expectSame($base['reality_settings']['public_key'], $proxy['reality-opts']['public-key'], 'Public key must stay unchanged');
        expectSame('xtls-rprx-vision', $proxy['flow'], 'Flow must stay unchanged');
        $yaml = Yaml::parse(Yaml::dump(['proxies' => [$proxy]], 10));
        expectSame(true, data_get($yaml, 'proxies.0.reality-opts.support-x25519mlkem768'), 'YAML must preserve the boolean');
        $loon = Loon::buildVless($uuid, subscriptionNode($clientSettings));
        expectSame(1, substr_count($loon, 'tls-profile=safari-ios-26'), 'Loon must get exactly one default profile');
    }
}

$override = array_replace($base, ['reality_client' => [
    'support_x25519mlkem768' => false, 'loon_tls_profile' => 'global',
], 'utls' => ['enabled' => true, 'fingerprint' => 'firefox']]);
$node = modelWith($override);
expectSame(false, $node->protocol_settings['reality_client']['support_x25519mlkem768'], 'Explicit false must survive reading');
$node->protocol_settings = $node->protocol_settings;
expectSame(false, $node->protocol_settings['reality_client']['support_x25519mlkem768'], 'Explicit false must survive saving');
$proxy = ClashMeta::buildVless($uuid, subscriptionNode($node->protocol_settings));
expectSame(false, isset($proxy['reality-opts']['support-x25519mlkem768']), 'Explicit false must disable MLKEM output');
expectSame('firefox', $proxy['client-fingerprint'], 'An explicit fingerprint must be preserved');
expectSame(true, str_contains(Loon::buildVless($uuid, subscriptionNode($node->protocol_settings)), 'tls-profile=global'), 'Explicit Loon profile must be preserved');

foreach ([0, 1] as $tls) {
    $settings = array_replace($base, ['tls' => $tls]);
    $proxy = ClashMeta::buildVless($uuid, subscriptionNode($settings));
    expectSame(false, isset($proxy['reality-opts']), 'Plain/TLS nodes must not get Reality options');
    expectSame(false, isset($proxy['client-fingerprint']), 'Plain/TLS fingerprints must stay unchanged');
    expectSame(false, str_contains(Loon::buildVless($uuid, subscriptionNode($settings)), 'tls-profile='), 'Plain/TLS Loon nodes must stay unchanged');
}

$xhttp = array_replace($base, ['network' => 'xhttp', 'flow' => null]);
$proxy = ClashMeta::buildVless($uuid, subscriptionNode($xhttp));
expectSame(true, $proxy['reality-opts']['support-x25519mlkem768'], 'XHTTP Reality must inherit defaults too');
expectSame(null, $proxy['flow'], 'XHTTP must not gain Vision flow');

$backend = ServerService::buildNodeConfig(modelWith($base));
expectSame(false, array_key_exists('reality_client', $backend), 'Client options must not enter the server API');
expectSame(false, array_key_exists('support_x25519mlkem768', $backend['tls_settings']), 'Server Reality settings must stay unchanged');
expectSame($base['reality_settings']['short_id'], $backend['tls_settings']['short_id'], 'Server short-id must stay unchanged');

$request = new ServerSave();
$request->merge(['type' => 'vless']);
$rules = $request->rules();
$factory = new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$booleanRule = $rules['protocol_settings.reality_client.support_x25519mlkem768'];
$profileRule = $rules['protocol_settings.reality_client.loon_tls_profile'];
expectSame(false, $factory->make(['value' => false], ['value' => $booleanRule])->fails(), 'Explicit false must pass validation');
expectSame(false, $factory->make(['value' => 'safari-ios-26'], ['value' => $profileRule])->fails(), 'Default Loon profile must pass validation');
expectSame(true, $factory->make(['value' => "chrome,udp=false"], ['value' => $profileRule])->fails(), 'Invalid/injected profile must fail validation');

$manager = new ProtocolManager(new Illuminate\Container\Container());
$classes = new ReflectionProperty(ProtocolManager::class, 'protocolClasses');
$classes->setValue($manager, [Clash::class, ClashMeta::class, Loon::class]);
foreach (['ClashParty/1.9.4', 'clash-party/1.9.4', 'MihomoParty/1.7.6', 'mihomo-party/1.7.6'] as $ua) {
    expectSame(ClashMeta::class, $manager->matchProtocolClassName($ua), 'Party must receive Mihomo subscriptions instead of legacy Clash');
}
expectSame(Clash::class, $manager->matchProtocolClassName('clash/1.18.0'), 'Legacy Clash must keep its existing handler');

echo json_encode(['checks' => $checks, 'passed' => true, 'database_used' => false]) . PHP_EOL;
