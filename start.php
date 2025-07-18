<?php

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

include 'src/Server.php';
include 'src/Resolvers/ResolverInterface.php';

// autoload
include 'vendor/autoload.php';


class myResolver implements ResolverInterface
{

    public function __construct(private LoopInterface $loop, public ?ServerExtended $server = null)
    {

    }

    public function getAnswer(array $queries, ?string $client = null): array
    {
        // Not use in overload
    }

    public function extractInfo(string $domain): array
    {
        // Extrait les informations du domaine demandé
        // Exemple de pattern : tasksActive.(all|[a-zA-Z0-9]+)\.([a-zA-Z0-9-_]+)\.$
        if (preg_match('/^tasksActive\.(all|[a-zA-Z0-9]+)\.([a-zA-Z0-9-_]+)\.$/', $domain, $matches)) {
            return [true, $matches[1], $matches[2]];
        }
        return [false, null, null];
    }

    public function getAnswerAsync(array $queries, ?string $client = null, ?Deferred $deferred = null): \React\Promise\PromiseInterface
    {
        global $_CACHE, $_TORESOLVE, $_TORESEND;

        if (!$deferred)
            $deferred = new Deferred();


        $query = $queries[0] ?? null;
        $domainAsked = $query->getName();

        [$isValid, $server, $domain] = $this->extractInfo($domainAsked);

        //var_dump("=====>" . var_export($isValid, true) . " , " . $domainAsked . " , " . $domain);

        if (!$isValid) // !preg_match('/^tasksActive\.(all|[a-zA-Z0-9]+)\.([a-zA-Z0-9-_]+)\.$/', $domain, $matches))
        {
            // si on n'a pas le bon pattern
            $deferred->reject(new RefusedTypeException("Unsupported domain"));
        } else {
            if ($_CACHE[$domain] ?? null) {

                $answers = [];

                if (!isset($_CACHE[$domain]['active']))
                    $_CACHE[$domain]['active'] = false;

                if ($_CACHE[$domain]['active']) {
                    foreach ($_CACHE[$domain]['ips'] as $ip) {

                        if ($ip['canBeJoin']) {
                            $answers[] = (new ResourceRecord())
                                ->setQuestion(false)
                                ->setTtl(1)
                                ->setType(RecordTypeEnum::TYPE_A)
                                ->setName($domainAsked . '.')
                                ->setRdata($ip['ip']);
                        }

                    }
                    $deferred->resolve($answers);
                } else {
                    // Si le domaine est dans le cache mais pas actif, on retentera plus tard..
                    if (!isset($_TORESEND[$domain]))
                        $_TORESEND[$domain] = [];

                    $_TORESEND[$domain][] = [
                            'deferred' => $deferred,
                            'client' => $client,
                            'queries' => $queries,
                            'server' => $server,
                            'domainAsked' => $domainAsked,
                            'domain' => $domain
                    ];
                }

            } else {
                // Injecte la demande de recherche dans la file d'attente
                $_TORESOLVE[$domain] = [
                    'infos' => [
                        'deferred' => $deferred,
                        'client' => $client,
                        'queries' => $queries,
                        'server' => $server,
                        'domainAsked' => $domainAsked,
                        'domain' => $domain
                    ]
                ];

                if ($this->server)
                    $this->loop->futureTick(fn() => $this->server->resolveDocker());
            }
        }

        return $deferred->promise();

    }

    public function allowsRecursion(): bool
    {
        return false;
    }

    public function isAuthority(string $domain): bool
    {
        [$isValid] = $this->extractInfo($domain);
        //var_dump("====================================>" . var_export($isValid,true));
        return $isValid;
    }
}

class RefusedTypeException extends \Exception
{

}

class FactoryExtended extends Factory
{
    public function createServer($address, $context = null)
    {
        $loop = $this->loop;

        return $this->resolveAddress($address)->then(function ($address) use ($loop, $context) {
            $socket = @\stream_socket_server($address, $errno, $errstr, \STREAM_SERVER_BIND, $context);

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
        if (isset($_SERVER['argv'][1]))
            $ip = $_SERVER['argv'][1];

        if (isset($_SERVER['argv'][2]))
            $port = $_SERVER['argv'][2];

        $context = stream_context_create(array(
            'socket' => array(
                'so_reuseport' => true, // enable SO_REUSEPORT
            )
        ));

        parent::__construct($resolver, $ip, $port, $loop, $context);

        global $_TORESOLVE, $_CACHE, $_TORESEND;

        $_CACHE = [];
        $_TORESOLVE = [];
        $_TORESEND = [];


        $this->resolver->server = $this;

        $this->loop->addPeriodicTimer(1, fn() => $this->retryResend());
        $this->loop->addPeriodicTimer(20, fn() => $this->emptyCache());

    }

    public function emptyCache()
    {
        global $_CACHE;
        // Empty the cache every 20 seconds
        foreach ($_CACHE as $domain => $data) {
            if (isset($data['timerConnectivity']['timer'])) {
                $this->loop->cancelTimer($data['timerConnectivity']['timer']);
                unset($_CACHE[$domain]['timerConnectivity']);
            }
            unset($_CACHE[$domain]);
        }


        echo "Cache emptied at " . date('Y-m-d H:i:s') . PHP_EOL;
    }

    public function retryResend()
    {
        global $_TORESEND, $_CACHE;

        //var_dump($_TORESEND);

        foreach ($_TORESEND as $domain => $clients) {
            echo "Retrying to send response for domain: $domain" . PHP_EOL;

            if (!isset($_CACHE[$domain]))
            {
                echo "Domain $domain not found in cache, skipping." . PHP_EOL;
                unset($_TORESEND[$domain]);
                continue;
            }

            if (isset($_CACHE[$domain]) && isset($_CACHE[$domain]['active']) && $_CACHE[$domain]['active']) {
                foreach ($clients as $data) {
                    // If the domain is active, we can send the response
                    $this->resolver->getAnswerAsync($data['queries'], $data['client'], $data['deferred'])
                        ->then(function (array $answers) use ($data) {
                            // Send the response to the client
                            if ($data['client']) {
                                echo "Sending response to " . $data['client'] . PHP_EOL;
                            }
                        });
                }
                unset($_TORESEND[$domain]);
            }

        }
    }

    public function testIpConnectivity($domain,string $idTimer = null)
    {
        global $_CACHE;

        echo "\n\nCALL FROM : " . $idTimer . "\n" ;

        $promises = [];
        foreach ($_CACHE[$domain]['ips'] as $ip) {
            $promises[] = \React\Promise\resolve($ip['canBeJoin'] ?? null)
                ->then(function ($canBeJoin) use ($ip, $domain) {

                    echo "Check connectivity for IP: " . $ip['ip'] . " in domain: " . $domain . ' actuel is ' . var_export($canBeJoin,true) .  PHP_EOL;

                    // check if we can connect to the IP with react php socket
                    // INSERT_YOUR_CODE
                    $deferred = new \React\Promise\Deferred();
                    $loop = $this->loop ?? \React\EventLoop\Loop::get();
                    $connector = new \React\Socket\Connector($loop);

                    $timeout = $this->timeout ?? 1.0; // Default timeout is 1 second, can be set as property
                    $timedOut = false;
                    echo "Attempting to connect to {$ip['ip']}:3306 with timeout {$timeout}s" . PHP_EOL;
                    $timer = $loop->addTimer($timeout, function () use (&$timedOut, $deferred, $ip) {
                        $timedOut = true;
                        echo "Connection to {$ip['ip']}:3306 timed out." . PHP_EOL;
                        $deferred->resolve(false);
                    });

                    $connector->connect('tcp://' . $ip['ip'] . ':3306')->then(
                        function (\React\Socket\ConnectionInterface $connection) use ($deferred, &$timer, &$timedOut, $loop, $ip) {
                            if ($timedOut) {
                                $connection->close();
                                return;
                            }
                            $buffer = '';
                            $connection->on('data', function ($data) use (&$buffer, $connection, $deferred, &$timer, $loop, &$timedOut, $ip) {
                                if ($timedOut) {
                                    $connection->close();
                                    return;
                                }
                                $buffer .= $data;
                                if (strlen($buffer) > 5) {
                                    if (isset($timer)) {
                                        $loop->cancelTimer($timer);
                                    }
                                    echo "Received data from {$ip['ip']}:3306, connection successful." . PHP_EOL;
                                    $connection->close();
                                    $deferred->resolve(true);
                                }
                            });
                            $connection->on('close', function () use (&$buffer, $deferred, &$timer, $loop, &$timedOut, $ip) {
                                if ($timedOut) {
                                    return;
                                }
                                if (isset($timer)) {
                                    $loop->cancelTimer($timer);
                                }
                                if (strlen($buffer) > 5) {
                                    echo "Connection to {$ip['ip']}:3306 closed after receiving data." . PHP_EOL;
                                    $deferred->resolve(true);
                                } else {
                                    echo "Connection to {$ip['ip']}:3306 closed without enough data." . PHP_EOL;
                                    $deferred->resolve(false);
                                }
                            });
                            $connection->on('error', function () use ($deferred, &$timer, $loop, &$timedOut, $ip) {
                                if ($timedOut) {
                                    return;
                                }
                                if (isset($timer)) {
                                    $loop->cancelTimer($timer);
                                }
                                echo "Error connecting to {$ip['ip']}:3306." . PHP_EOL;
                                $deferred->resolve(false);
                            });
                        },
                        function () use ($deferred, &$timer, $loop, &$timedOut, $ip) {
                            if ($timedOut) {
                                return;
                            }
                            if (isset($timer)) {
                                $loop->cancelTimer($timer);
                            }
                            echo "Failed to connect to {$ip['ip']}:3306." . PHP_EOL;
                            $deferred->resolve(false);
                        }
                    );

                    return $deferred->promise()->then(function ($canBeJoin) use ($ip) {
                        $ip['canBeJoin'] = $canBeJoin;
                        return $ip;
                    });



                 //   $ip['canBeJoin'] = (bool)rand(0,1); // Simulate that the IP can be joined
                 //   return $ip;
                });
        }

        return \React\Promise\all($promises)->then(function ($results) use ($domain,&$_CACHE) {
            $_CACHE[$domain]['ips'] = $results;
            return $results;
        });
    }
    public function resolveDocker()
    {
        global $_TORESOLVE, $_CACHE, $_TORESEND;
        if (count($_TORESOLVE) > 0) {
            foreach ($_TORESOLVE as $domain => $data) {

                $serviceName = $data['infos']['domain'];
                $_CACHE[$data['infos']['domain']]['ips'] = [];
                $_CACHE[$data['infos']['domain']]['active'] = false;

                $client = new Clue\React\Docker\Client();
                $client->serviceList()->then(function (array $services) use ($client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
                    foreach ($services as $service) {
                        $client->taskList($service['ID'])->then(function (array $tasks) use ($service, $client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
                            if ($service['Spec']['Name'] == $serviceName) {
                                echo "Service: " . $service['Spec']['Name'] . PHP_EOL;
                                $nbTasks = count($tasks);
                                foreach ($tasks as $task) {
                                    $client->taskInspect($task['ID'])->then(function (array $taskDetails) use ($service, $data, $nbTasks, &$_CACHE, &$_TORESEND) {
                                        if (isset($taskDetails['NetworksAttachments'][0]['Addresses'])) {
                                            if ($taskDetails['Status']['State'] == 'running') {
                                                $ipRange = $taskDetails['NetworksAttachments'][0]['Addresses'];
                                                $ip = explode('/', $ipRange[0])[0]; // Get the IP address part before the slash
                                                $_CACHE[$data['infos']['domain']]['ips'][] = ['canBeJoin' => null , 'ip' =>  $ip ] ;
                                                if (count($_CACHE[$data['infos']['domain']]['ips']) == $nbTasks) {
                                                    echo "All tasks resolved for service: " . $service['Spec']['Name'] . PHP_EOL;
                                                    foreach ($_CACHE[$data['infos']['domain']]['ips'] as $displayIp) {
                                                        echo "IPs: " . $displayIp['ip'] . PHP_EOL;
                                                    }


                                                    $this->testIpConnectivity($data['infos']['domain'])
                                                        ->then(function ($resultIps) use ($data, &$_CACHE, &$_TORESEND) {
                                                            // Update the cache with the connectivity results
                                                            $_CACHE[$data['infos']['domain']]['active'] = true;


                                                            if (!isset($_TORESEND[$data['infos']['domain']]))
                                                                $_TORESEND[$data['infos']['domain']] = [] ;

                                                            $_TORESEND[$data['infos']['domain']][] = [
                                                                'deferred' => $data['infos']['deferred'],
                                                                'client' => $data['infos']['client'],
                                                                'queries' => $data['infos']['queries'],
                                                                'server' => $data['infos']['server'],
                                                                'domainAsked' => $data['infos']['domainAsked'],
                                                                'domain' => $data['infos']['domain']
                                                            ];

                                                            $this->loop->futureTick(fn() => $this->retryResend());
                                                            // add Periodic Check of IPs
                                                            $_CACHE[$data['infos']['domain']]['timerConnectivity'] = ['id' => uniqid() . rand(1,10000) ] ;
                                                            $_CACHE[$data['infos']['domain']]['timerConnectivity']['timer'] = $this->loop->addPeriodicTimer(1,fn() => $this->testIpConnectivity($data['infos']['domain'],$_CACHE[$data['infos']['domain']]['timerConnectivity']['id']));
//

                                                    });


                                                }

                                            }

                                        }

                                    })->otherwise(function (Exception $e) {
                                        echo 'Error inspecting task: ' . $e->getMessage() . PHP_EOL;
                                    });


                                    echo "Status: " . $task['Status']['State'] . PHP_EOL;
                                    echo "Node: " . $task['NodeID'] . PHP_EOL;
                                    echo PHP_EOL;
                                }
                            }
                        })->otherwise(function (Exception $e) {
                            echo 'Error listing tasks: ' . $e->getMessage() . PHP_EOL;
                        });
                    }
                }, function (Exception $e) {
                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                });

                unset($_TORESOLVE[$domain]);


            }
        }
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


$server = new ServerExtended(new myResolver(Loop::get()));

