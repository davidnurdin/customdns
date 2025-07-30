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

if (isset($argv[1]))
    $dnsHelperName = $argv[1] ;
else
    $dnsHelperName = 'dns_dns-helper' ;

$client = new Clue\React\Docker\Client();
$client->serviceInspect($dnsHelperName,false)->then(function($objectDnsHelper) use ($client,$dnsHelperName)
{
    $version = $objectDnsHelper->Version->Index ?? 0;
    echo "VERSION: " . $version . PHP_EOL;
    $newObject = $objectDnsHelper->Spec;
    if (!isset($newObject->TaskTemplate->Networks) || (count($newObject->TaskTemplate->Networks) == 0))
    {
        echo "No networks to remove" . PHP_EOL;
        return;
    }

    $newObject->TaskTemplate->Networks = [] ;
    $client->serviceUpdate($objectDnsHelper->ID, $version, $newObject)->then(function ($result) use ($client,$dnsHelperName) {
        // show the status
        checkStatus($client,$dnsHelperName);
        var_dump($result);

    });
}) ;

function checkStatus($client,$dnsHelperName)
{
        $client->serviceInspect($dnsHelperName,false)->then(function($objectDnsHelper) use ($client,$dnsHelperName) {
            if (isset($objectDnsHelper->UpdateStatus)) {
                if ($objectDnsHelper->UpdateStatus->State === 'completed') {
                    echo "Service update completed" . PHP_EOL;
                } elseif ($objectDnsHelper->UpdateStatus->State === 'updating') {
                    echo "Service is still updating" . PHP_EOL;
                    $loop = Loop::get();
                    $loop->addTimer(0.1, fn() => checkStatus($client,$dnsHelperName));
                } else {
                    echo "Service state: " . $objectDnsHelper->State . PHP_EOL;
                }
            }
            else
            {
                echo "Service update status not available" . PHP_EOL;
                $loop = Loop::get();
                $loop->addTimer(0.1, fn() => checkStatus($client,$dnsHelperName));
            }
        });
}
