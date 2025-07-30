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

$dnsHelperName = 'dns_dns-helper' ;

/**
 * @param $client
 * @param $network
 * @param $objectDnsHelper
 * @param $dnsHelperName
 * @return void
 */

$obj = new stdClass() ;
$obj2 = new stdClass() ;
$obj2->$dnsHelperName = true ;
$obj->service = $obj2 ;


$client->taskList(['service' => [ $dnsHelperName => true ] ])->then(function($objectDnsHelper)
{

    var_dump(count($objectDnsHelper));

}) ;
