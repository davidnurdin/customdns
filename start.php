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
use React\Socket\Connector;

include 'src/Server.php';
include 'src/Resolvers/ResolverInterface.php';

// autoload
include 'vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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
                    foreach ($_CACHE[$domain]['ipsActive'] as $ip) {

                        // send only IP on same network : TODO
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
                    $this->loop->addTimer(0.1, fn() => $this->server->resolveDocker());
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
     //   return; // TODO : voir

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

            if (!isset($_CACHE[$domain])) {
                echo "Domain $domain not found in cache, skipping." . PHP_EOL;
                unset($_TORESEND[$domain]);
                continue;
            }

            var_export($_CACHE[$domain]);

            if (isset($_CACHE[$domain]) && isset($_CACHE[$domain]['active']) && $_CACHE[$domain]['active'] === true) {

                echo "NB CLIENT : " . count($clients) . PHP_EOL;

                foreach ($clients as $data) {

                    echo "RESEND FOR CLIENT ..." . PHP_EOL;

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
            } else {
                echo "TRACE1\n";
            }

        }
    }

    public function createTimeout(int $time, LoopInterface $loop, $deferred, $message,$idTimer)
    {
        // 1er timer
        echo "Timeout on : " . $message . " after " . $time . " seconds." . PHP_EOL;
        $timer = $loop->addTimer($time, function () use ($deferred, $message, $time,$idTimer) {
            echo "Timeout is CATCH on " . $message . " after " . $time . " seconds." . PHP_EOL;
            $this->rejectOrResolveFalse("Timeout : " . $message,$idTimer, $deferred);
        });

        return $timer;
    }

    public function dataFromProxy($chunk,$loop,$proxy,$deferred,$timer = null,$idTimer = null)
    {
        var_dump('(2) SIZE DATA : ' . strlen($chunk) . ' DATA : ' . bin2hex($chunk));
        // echo $chunk;
        if ($timer)
            $loop->cancelTimer($timer);

        echo "Received data from proxy: " . substr($chunk, 0, 50) . "...\n"; // Affiche les 50 premiers caractères
        $proxy->close();
        if (strlen($chunk) > 5) {
            echo "Data received successfully, connection is good.\n";
            $deferred->resolve(true);
        } else {
            echo "Data received is too short, connection might not be good.\n";
            $this->rejectOrResolveFalse("Data too short",$idTimer, $deferred);
        }
    }


    public function rejectOrResolveFalse($message,$idTimer,$deferred)
    {
        if ($idTimer === null)
            $deferred->reject(new \Exception($message));
        else
            $deferred->resolve(false);

    }

    public function testIpConnectivity($domain, ?string $idTimer)
    {
        global $_CACHE;


        echo "\n\nCALLXXXX FROM : " . $idTimer . "\n";

        $promises = [];
        foreach ($_CACHE[$domain]['ips'] as $ip) {
            $promises[] = \React\Promise\resolve($ip['canBeJoin'] ?? null)
                ->then(function ($canBeJoin) use ($ip, $domain,$idTimer) {

                    echo "Check connectivity for IP: " . $ip['ip'] . " in domain: " . $domain . ' actuel is ' . var_export($canBeJoin, true) . PHP_EOL;

                    // check if we can connect to the IP with react php socket
                    // INSERT_YOUR_CODE
                    $deferred = new \React\Promise\Deferred();
                    echo "NEW DEFERRED for IP: " . $ip['ip'] . " in domain: " . $domain . PHP_EOL;

                    $loop = $this->loop ?? \React\EventLoop\Loop::get();


                    $connector = new Connector([
                        'unix' => true,
                    ]);

                    $timer1 = $this->createTimeout(2, $loop, $deferred, "Connection(1) to {$ip['ip']}:3306", $idTimer);
                    $unixSocketPath = '/var/run/dns-helper/helper.sock';
                    $connector->connect("unix://$unixSocketPath")->then(function (React\Socket\ConnectionInterface $proxy) use ($loop, $deferred, $ip, $timer1,$idTimer) {
                        echo "Connecté à la socket Unix SOCKS5\n";
                        $loop->cancelTimer($timer1);

                        // 2eme timer
                        $timer2 = $this->createTimeout(2, $loop, $deferred, "Connection(2) to {$ip['ip']}:3306", $idTimer);
                        // Étape 1 : Négociation SOCKS5 (no auth)
                        $proxy->write("\x05\x01\x00");

//                        $proxy->once('close', function () use ($timer2, $loop,$deferred) {
//                            $loop->cancelTimer($timer2);
//                            $deferred->resolve(false);
//                            echo "\n(2) Connexion fermée\n";
//                        });


                        $proxy->once('data', function ($data) use ($proxy, $deferred, $ip, $timer2, $loop,$idTimer) {
                            $loop->cancelTimer($timer2);
                            if ($data !== "\x05\x00") {
                                $proxy->close();
                                echo "Proxy SOCKS5 : méthode non supportée ou erreur\n";
                                echo "Connection(2) to {$ip['ip']}:3306 not support." . PHP_EOL;

                                $this->rejectOrResolveFalse("Timeout(2)",$idTimer, $deferred);
                                return;
                            }

                            echo "Méthode d'auth OK, envoi de la requête de connexion...\n";


                            // Étape 2 : Demande de connexion à www.google.fr:80
                            $addr = $ip['ip'];
                            $port = 3306;

                            $addrBytes = chr(strlen($addr)) . $addr;
                            $portBytes = pack('n', $port);

                            $request = "\x05\x01\x00\x03" . $addrBytes . $portBytes;
                            $proxy->write($request);


                            $timer3 = $this->createTimeout(2, $loop, $deferred, "Connection(3) to {$ip['ip']}:3306", $idTimer);
//                            $proxy->once('close', function () use ($timer3, $loop,$deferred) {
//                                $loop->cancelTimer($timer3);
//                                $deferred->resolve(false);
//                                echo "\n(3) Connexion fermée\n";
//                            });

                            $proxy->once('data', function ($data) use ($proxy, $addr, $port, $deferred, $ip, $timer3, $loop,$idTimer) {
                                $loop->cancelTimer($timer3);
                                var_dump('(1) SIZE DATA : ' . strlen($data) . ' DATA : ' . bin2hex($data));

                                if (strlen($data) < 2 || $data[1] !== "\x00") {
                                    $hex = strtoupper(implode(' ', str_split(bin2hex($data), 2)));
                                    echo "[ERR] => Réponse du proxy SOCKS5 : " . $hex . "\n";
                                    echo "Connexion refusée ou erreur SOCKS5\n";
                                    echo "Connection(3) to {$ip['ip']}:3306 connexion refuse." . PHP_EOL;
                                    $this->rejectOrResolveFalse("Timeout(3)",$idTimer, $deferred);
                                    $proxy->close();
                                    return;
                                }

                                if (strlen($data) > 10) {
                                    // restant de la requete
                                    $remainingData = substr($data, 10);
                                    $this->dataFromProxy($remainingData,$loop,$proxy,$deferred,null,$idTimer) ;

                                }
                                echo "Connexion à " . $addr . ":" . $port . " établie via SOCKS5\n";

                                // Étape 3 : Envoi de la requête HTTP GET
//                                $httpRequest = "GET / HTTP/1.1\r\nHost: www.google.fr\r\nConnection: close\r\n\r\n";
//                                $proxy->write($httpRequest);

                                $timer4 = $this->createTimeout(2, $loop, $deferred, "Connection(4) to {$ip['ip']}:3306", $idTimer);
                                $proxy->on('data', fn($chunk) => $this->dataFromProxy($chunk,$loop,$proxy,$deferred,$timer4,$idTimer));

                                // soit on recois des data , soit on a un timeout
                                // TODO : voir comment gerer les close ?! (peut on faire un deferred = true, puis un false ?) (ou testé si le deferred a été triggered)
//                                $proxy->once('close', function () use ($timer4, $loop,$deferred) {
//                                    $loop->cancelTimer($timer4);
//                                    $deferred->resolve(false);
//                                    echo "\n(4) Connexion fermée\n";
//                                });
                            });
                        });
                    }, function (Exception $e) use ($deferred) {
                        // defered false
                        $this->rejectOrResolveFalse("Timeout(E4)",null, $deferred);
                        echo "|||||||||||||| Échec de connexion à la socket Unix : " . $e->getMessage() . "\n";
                    });


                    return $deferred->promise()->then(function ($canBeJoin) use ($ip) {
                        //$canBeJoin =  (bool)rand(0,1); ; // TODO enlever
                        echo " ========> RESPONSE : " . $ip['ip'] . " can be join : " . var_export($canBeJoin, true) . "\n";
                        $ip['canBeJoin'] = $canBeJoin;
                        return $ip;
                    });


                    //   $ip['canBeJoin'] = (bool)rand(0,1); // Simulate that the IP can be joined
                    //   return $ip;
                });
        }

        echo "END FOREACH\n";

        if ($idTimer === null)
        {
            $result = \React\Promise\any($promises)->then(function ($results) use ($domain, &$_CACHE) {

//                var_dump($results);
//                die();

                echo "=>>>>>>>>>>>>>>> NOWWWW (one OK) WRITE ips TO domain : " . $domain . " count ( " . count($results) . " \n";
                var_dump($results);

                $_CACHE[$domain]['ipsActive'] = [ $results ];
                return $results;
            });
        }
        else {

            echo "::::::::::::::::::::::::::::::::::::::::::::::::::: =========> ALL" ;

            $result = \React\Promise\all($promises)->then(function ($results) use ($domain, &$_CACHE) {
                // TODO : voir si y'a pas des timer en concurrence ?
                echo "WRITE ips TO domain : " . $domain . " count ( " . count($results) . " \n";
                var_export($results);

                $_CACHE[$domain]['ipsActive'] = $results;
                return $results;
            });
        }

        echo "END PROMISE\n";

        return $result;
    }

    public
    function resolveDocker()
    {
        global $_TORESOLVE, $_CACHE, $_TORESEND;
        if (count($_TORESOLVE) > 0) {
            foreach ($_TORESOLVE as $domain => $data) {

                $serviceName = $data['infos']['domain'];
                $_CACHE[$data['infos']['domain']]['ips'] = [];
                $_CACHE[$data['infos']['domain']]['ipsActive'] = [];
                $_CACHE[$data['infos']['domain']]['active'] = false;



                $client = new Clue\React\Docker\Client();
                $client->serviceList()->then(function (array $services) use ($client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
                    foreach ($services as $service) {
                        if ($service['Spec']['Name'] == $serviceName) {


                            $clientGwInspect = new Clue\React\Docker\Client();
                            $clientGwInspect->networkInspect('docker_gwbridge')->then(
                                function (array $network) use ($clientGwInspect, $serviceName, $data, &$_CACHE, &$_TORESEND,$client,$service) {
                                    // Check if the network is active
                                    foreach ( $network['Containers'] as $containerID => $containerInfos)
                                    {
                                        // search the client source in this list with same IPV4
                                        if (explode('/',$containerInfos['IPv4Address'])[0] == explode(':',$data['infos']['client'])[0]) {
                                            echo "Found client in docker_gwbridge network: " . $containerID . PHP_EOL;
                                            // get the networks and connect to it : todo : exclude ingress?
                                            // TODO : ne connect que si le current node est bien le meme !
                                            echo "Network: " . $network['NetworkID'] . PHP_EOL;
                                            echo "Addr:" . $network['Addr'] . PHP_EOL;
                                            echo "Try to connect to network: " . $network['NetworkID'] . " On container : " . $containerID . PHP_EOL;
                                            // ASK DNS HELPER to join NETWORK
                                            $client->networkConnect($network['NetworkID'], $containerID)->then(function () use ($service, $client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
                                                echo "Connected to network: " . $service['Spec']['Name'] . PHP_EOL;
                                            })->otherwise(function (Exception $e) {
                                                echo 'Error connecting to network: ' . $e->getMessage() . PHP_EOL;
                                            });
                                            die();
                                            
                                            $_CACHE[$data['infos']['domain']]['networks'][$network['NetworkID']] = $network['Addr'];
                                        }

                                    }
                                },
                                function (Exception $e) {
                                    echo 'Error inspecting network: ' . $e->getMessage() . PHP_EOL;
                                }
                            );




                            $client->taskList($service['ID'])->then(function (array $tasks) use ($service, $client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
                                    echo "Service: " . $service['Spec']['Name'] . PHP_EOL;

                                    // filter task get only Running AND have Addresses
                                    $tasks = array_filter($tasks, function ($task) {
                                        return $task['Status']['State'] == 'running' && isset($task['NetworksAttachments'][0]['Addresses']);
                                    });



                                    var_dump($data['infos']['client']);die();

                                    $_CACHE[$data['infos']['domain']]['nbTasksToResolve'] = count($tasks);
                                    $_CACHE[$data['infos']['domain']]['nbTasksResolved'] = 0;
                                    $_CACHE[$data['infos']['domain']]['networks'] = [];

                                    foreach ($tasks as $task) {
                                        $client->taskInspect($task['ID'])->then(function (array $taskDetails) use ($service, $data, &$_CACHE, &$_TORESEND,$client,$task,$serviceName) {
                                            var_dump('TASK : ' . $taskDetails['ID'] . PHP_EOL);


//                                            // get the networks and connect to it : todo : exclude ingress?
//                                            // TODO : ne connect que si le current node est bien le meme !
//                                            foreach ($service['Endpoint']['VirtualIPs'] as $network) {
//                                                echo "Network: " . $network['NetworkID'] . PHP_EOL;
//                                                echo "Addr:" . $network['Addr'] . PHP_EOL;
//                                                echo "Try to connect to network: " . $network['NetworkID'] . " On container : " . $task['Status']['ContainerStatus']['ContainerID'] . PHP_EOL;
//                                                // ASK DNS HELPER to join NETWORK
//                                                $client->networkConnect($network['NetworkID'], $task['Status']['ContainerStatus']['ContainerID'])->then(function () use ($service, $client, $serviceName, $data, &$_CACHE, &$_TORESEND) {
//                                                    echo "Connected to network: " . $service['Spec']['Name'] . PHP_EOL;
//                                                })->otherwise(function (Exception $e) {
//                                                    echo 'Error connecting to network: ' . $e->getMessage() . PHP_EOL;
//                                                });
//
//                                                $_CACHE[$data['infos']['domain']]['networks'][$network['NetworkID']] = $network['Addr'];
//                                            }

                                            // TODO : faudra peut etre spécifié le nom de réseau ou le déduire depuis la source ?
                                            $_CACHE[$data['infos']['domain']]['nbTasksResolved']++;
                                            foreach ($taskDetails['NetworksAttachments'] as $netWork) {
                                                $ipRange = $netWork['Addresses'];
                                                $ip = explode('/', $ipRange[0])[0]; // Get the IP address part before the slash
                                                $_CACHE[$data['infos']['domain']]['ips'][] = ['ip' => $ip];
                                            }


                                            if ($_CACHE[$data['infos']['domain']]['nbTasksResolved'] == $_CACHE[$data['infos']['domain']]['nbTasksToResolve']) {
                                                echo "All tasks resolved for service: " . $service['Spec']['Name'] . PHP_EOL;

                                                foreach ($_CACHE[$data['infos']['domain']]['ips'] as $displayIp) {
                                                    echo "IPs: " . $displayIp['ip'] . PHP_EOL;
                                                }


                                                $this->testIpConnectivity($data['infos']['domain'],null)
                                                    ->then(function ($resultIps) use ($data, &$_CACHE, &$_TORESEND) {
                                                        // Update the cache with the connectivity results
                                                        echo "=========+> SET ACTIVE DOMAIN : " . $data['infos']['domain'] . PHP_EOL;

                                                        $_CACHE[$data['infos']['domain']]['active'] = true;


                                                        if (!isset($_TORESEND[$data['infos']['domain']]))
                                                            $_TORESEND[$data['infos']['domain']] = [];

                                                        $_TORESEND[$data['infos']['domain']][] = [
                                                            'deferred' => $data['infos']['deferred'],
                                                            'client' => $data['infos']['client'],
                                                            'queries' => $data['infos']['queries'],
                                                            'server' => $data['infos']['server'],
                                                            'domainAsked' => $data['infos']['domainAsked'],
                                                            'domain' => $data['infos']['domain']
                                                        ];

                                                        $this->loop->addTimer(0.2, (fn() => $this->retryResend()));
                                                        // add Periodic Check of IPs
                                                        $_CACHE[$data['infos']['domain']]['timerConnectivity'] = ['id' => uniqid() . rand(1, 10000)];
                                                        $_CACHE[$data['infos']['domain']]['timerConnectivity']['timer'] = $this->loop->addPeriodicTimer(5, fn() => $this->testIpConnectivity($data['infos']['domain'], $_CACHE[$data['infos']['domain']]['timerConnectivity']['id']));
//

                                                    });


                                            }

                                        })->otherwise(function (Exception $e) {
                                            echo 'Error inspecting task: ' . $e->getMessage() . PHP_EOL;
                                        });


                                        echo "Status: " . $task['Status']['State'] . PHP_EOL;
                                        echo "Node: " . $task['NodeID'] . PHP_EOL;
                                        echo PHP_EOL;
                                    }

                            })->otherwise(function (Exception $e) {
                                echo 'Error listing tasks: ' . $e->getMessage() . PHP_EOL;
                            });
                        }

                    }
                }, function (Exception $e) {
                    echo 'Error: ' . $e->getMessage() . PHP_EOL;
                });

                unset($_TORESOLVE[$domain]);


            }
        }
    }

    public
    function onMessage(string $message, string $address, SocketInterface $socket): void
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

    public
    function handleQueryFromStreamAsync(string $buffer, ?string $client = null): PromiseInterface
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

