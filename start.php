<?php

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
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

include 'src/Server.php';
include 'src/Resolvers/ResolverInterface.php';

// autoload
include 'vendor/autoload.php';

class myResolver implements ResolverInterface
{

    public function getAnswer(array $queries, ?string $client = null): array
    {
        // Not use in overload
        // TODO: Implement getAnswer() method.
        $debug = 1 ;
    }

    public function getAnswerAsync(array $queries, ?string $client = null) : \React\Promise\PromiseInterface
    {
        $debug = 2 ;

        $deferred = new Deferred();

        // TODO: Implement getAnswer() method.
        $debug = 1 ;

        $answers = [] ;
        $resolveInternal = false ;
        foreach ($queries as $query) {
            /** @var ResourceRecord $query */
            var_dump($query->getName());
            if (true) { // $query->getName() === "david.") {
                // If the query is for "david", return the answer

                $resolveInternal = true ;
                $config = React\Dns\Config\Config::loadSystemConfigBlocking();
                $config->nameservers = ['8.8.8.8'];
//                if (!$config->nameservers) {
//                    $config->nameservers[] = '8.8.8.8';
//                }
                $factory = new React\Dns\Resolver\Factory();
                $dns = $factory->create($config);

                //if ($query->getType() ==
                $host = "google.fr" ;
                $host = substr($query->getName(),0,-1);
                var_dump("TYPE:". $query->getType());

                // $dns->resolveAll ??

                if ($host == 'david')
                {
                    $answers[] = (new ResourceRecord())
                        ->setQuestion(false)
                        ->setTtl(1)
                        ->setType(RecordTypeEnum::TYPE_A)
                        ->setName($host)
                        ->setRdata('6.6.6.6');

                    $deferred->resolve($answers);
                }
                else {
                    if ($query->getType() !== 1)
                        $deferred->reject(new RefusedTypeException("Unsupported type"));
                    else {
                        $dns->resolve($host)->then(function ($ip) use ($answers, $deferred, $host) {
                            echo "Host: $ip\n";

                            //$host = "david" ;

                            $answers[] = (new ResourceRecord())
                                ->setQuestion(false)
                                ->setTtl(1)
                                ->setType(RecordTypeEnum::TYPE_A)
                                ->setName($host)
                                ->setRdata($ip);

                            $deferred->resolve($answers);
                        });
                    }
                }


            }

        }

        if (!$resolveInternal) {
            $deferred->reject(new RefusedTypeException("Unsupported domain"));
        }

        return $deferred->promise();

        //return $answers;
    }

    public function allowsRecursion(): bool
    {
        // TODO: Implement allowsRecursion() method.
        $debug = 1 ;
        return false ;
    }

    public function isAuthority(string $domain): bool
    {
        // TODO: Implement isAuthority() method.
        if ($domain == "david.")
            return true ;

        return false ;
    }
}

class RefusedTypeException extends \Exception
{

}

class FactoryExtended extends Factory
{
    public function createServer($address,$context = null)
    {
        $loop = $this->loop;

        return $this->resolveAddress($address)->then(function ($address) use ($loop,$context) {
            $socket = @\stream_socket_server($address, $errno, $errstr, \STREAM_SERVER_BIND,$context);

            if (!$socket) {
                throw new Exception('Unable to create server socket: ' . $errstr, $errno);
            }

            return new Socket($loop, $socket);
        });
    }

}
class ServerExtended extends \CatFerq\ReactPHPDNS\Server
{

    public function __construct(private ResolverInterface $resolver, string $ip = '0.0.0.0', int $port = 53, ?\React\EventLoop\LoopInterface $loop = null)
    {
        $context = stream_context_create(array(
            'socket' => array(
                'so_reuseport' => true, // enable SO_REUSEPORT
            )
        ));

        parent::__construct($resolver, $ip, $port, $loop,$context);
    }

    public function onMessage(string $message, string $address, SocketInterface $socket): void
    {
        $this->handleQueryFromStreamAsync($message, $address)->then(
            function (string $response) use ($socket, $address) {
                $socket->send($response, $address);
            },
            function (\Throwable $e) {
                // Log ou ignore si nécessaire
                echo "Erreur pendant traitement de la requête DNS : " . $e->getMessage() . PHP_EOL;
            }
        );
    }

    public function handleQueryFromStreamAsync(string $buffer, ?string $client = null): PromiseInterface
    {
        $message = Decoder::decodeMessage($buffer);

        $responseMessage = clone $message;
        $responseMessage->getHeader()
            ->setResponse(true)
            ->setRecursionAvailable($this->resolver->allowsRecursion())
            ->setAuthoritative($this->isAuthoritative($message->getQuestions()));

        // Appel asynchrone du resolver
        try {
            return $this->resolver->getAnswerAsync($responseMessage->getQuestions(), $client)
                ->then(function (array $answers) use ($responseMessage) {
                    $responseMessage->setAnswers($answers);
                    $this->needsAdditionalRecords($responseMessage);
                    return Encoder::encodeMessage($responseMessage);
                })
                ->otherwise(function (\Throwable $e) use ($responseMessage) {
                    $responseMessage->setAnswers([]);

                    if ($e instanceof UnsupportedTypeException) {
                        $responseMessage->getHeader()->setRcode(Header::RCODE_NOT_IMPLEMENTED);
                    } elseif ($e instanceof RefusedTypeException) {
                        $responseMessage->getHeader()->setRcode(Header::RCODE_REFUSED);
                    } else {
                        $responseMessage->getHeader()->setRcode(Header::RCODE_SERVER_FAILURE);
                    }

                    return Encoder::encodeMessage($responseMessage);
                });
        } catch (\Throwable $e) {
            // Catch fallback synchronisé
            $responseMessage->setAnswers([]);
            $responseMessage->getHeader()->setRcode(Header::RCODE_SERVER_FAILURE);
            return \React\Promise\resolve(Encoder::encodeMessage($responseMessage));
        }


    }
}


$server = new ServerExtended(new myResolver());

