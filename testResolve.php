<?php

use CatFerq\ReactPHPDNS\Entities\Header;
use CatFerq\ReactPHPDNS\Entities\ResourceRecord;
use CatFerq\ReactPHPDNS\Enums\RecordTypeEnum;
use CatFerq\ReactPHPDNS\Exceptions\UnsupportedTypeException;
use CatFerq\ReactPHPDNS\Resolvers\ResolverInterface;
use CatFerq\ReactPHPDNS\Services\Decoder;
use CatFerq\ReactPHPDNS\Services\Encoder;
use React\Datagram\SocketInterface;
use React\Dns\Model\Message;
use React\Dns\Query\Query;
use React\Dns\Query\TcpTransportExecutor;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

// autoload
include 'vendor/autoload.php';



$executor = new TcpTransportExecutor('8.8.8.8:53');

$name = 'google.fr' ;

$executor->query(
    new Query($name, Message::TYPE_A, Message::CLASS_IN),

)->then(function (Message $message) {
    foreach ($message->answers as $answer) {
        var_dump($answer);
    }
}, 'printf');
