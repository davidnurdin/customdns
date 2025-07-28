<?php
// IMPORTANT : docker node promote swarm-2 swarm-3
declare(strict_types=1);


use CatFerq\ReactPHPDNS\Entities\Header;
use CatFerq\ReactPHPDNS\Entities\ResourceRecord;
use CatFerq\ReactPHPDNS\Enums\RecordTypeEnum;
use CatFerq\ReactPHPDNS\Exceptions\UnsupportedTypeException;
use CatFerq\ReactPHPDNS\Resolvers\ResolverInterface;
use CatFerq\ReactPHPDNS\Services\Decoder;
use CatFerq\ReactPHPDNS\Services\Encoder;
use React\Datagram\Factory;
use React\Datagram\Socket;
use React\Datagram\SocketInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

include 'src/Server.php';
include 'src/Resolvers/ResolverInterface.php';

// autoload
include 'vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$client = new Clue\React\Docker\Client();

$network = [] ;
$network['NetworkID'] = $argv[1] ; // 'dns_test-network-mysql' ; // 'dns_test-network-mysql'

$client->serviceInspect('dns_dns-helper',false)->then(function($objectDnsHelper) use ($client,$network)
{
    $client->networkInspect($network['NetworkID'])->then(function($objectNetwork) use ($client,$network,$objectDnsHelper)
    {
        $network['NetworkID'] = $objectNetwork['Id'] ;

        $version = $objectDnsHelper->Version->Index ?? 0;
        echo "VERSION: " . $version . PHP_EOL;

        $newObject = $objectDnsHelper->Spec;
        foreach ($newObject->TaskTemplate->Networks as $networkSource)
        {
            if ($networkSource->Target === $network['NetworkID']) {
                echo "Network already exists in TaskTemplate" . PHP_EOL;
                return;
            }
        }

        // add a network to newObject
        $newNetwork = new stdClass();
        $newNetwork->Target = $network['NetworkID'];
        $newObject->TaskTemplate->Networks[] = $newNetwork;

        $client->serviceUpdate($objectDnsHelper->ID, $version, $newObject)->then(function ($result) use ($client) {
            // show the status
            checkStatus($client);
            var_dump($result);

        });
    });

}) ;

function checkStatus($client)
{
        $client->serviceInspect('dns_dns-helper',false)->then(function($objectDnsHelper) use ($client) {
            if (isset($objectDnsHelper->UpdateStatus)) {
                if ($objectDnsHelper->UpdateStatus->State === 'completed') {
                    echo "Service update completed" . PHP_EOL;
                } elseif ($objectDnsHelper->UpdateStatus->State === 'updating') {
                    echo "Service is still updating" . PHP_EOL;
                    $loop = Loop::get();
                    $loop->addTimer(0.1, fn() => checkStatus($client));
                } else {
                    echo "Service state: " . $objectDnsHelper->State . PHP_EOL;
                }
            }
            else
            {
                echo "Service update status not available" . PHP_EOL;
                $loop = Loop::get();
                $loop->addTimer(0.1, fn() => checkStatus($client));
            }
        });
}
