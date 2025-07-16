<?php

namespace CatFerq\ReactPHPDNS;

use CatFerq\ReactPHPDNS\Entities\Header;
use CatFerq\ReactPHPDNS\Entities\Message;
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

class Server
{
    public function __construct(
        private readonly ResolverInterface $resolver,
        private readonly string            $ip = '0.0.0.0',
        private readonly int               $port = 53,
        private ?LoopInterface             $loop = null
    )
    {
        $this->loop ??= Loop::get();

        $factory = new Factory($this->loop);
        $factory->createServer($this->ip . ':' . $this->port)->then(function (Socket $server) {
            $server->on('message', [$this, 'onMessage']);
        })->otherwise(function (\Exception $exception) {
            echo $exception->getMessage();
        });
    }

    public function start(): void
    {
        set_time_limit(0);
        $this->loop->run();
    }

    public function onMessage(string $message, string $address, SocketInterface $socket): void
    {
        $socket->send($this->handleQueryFromStream($message, $address), $address);
    }

    public function handleQueryFromStream(string $buffer, ?string $client = null): string
    {
        $message = Decoder::decodeMessage($buffer);

        $responseMessage = clone $message;
        $responseMessage->getHeader()
                        ->setResponse(true)
                        ->setRecursionAvailable($this->resolver->allowsRecursion())
                        ->setAuthoritative($this->isAuthoritative($message->getQuestions()));

        try {
            $answers = $this->resolver->getAnswer($responseMessage->getQuestions(), $client);
            $responseMessage->setAnswers($answers);
            $this->needsAdditionalRecords($responseMessage);

            return Encoder::encodeMessage($responseMessage);
        } catch (UnsupportedTypeException) {
            $responseMessage
                ->setAnswers([])
                ->getHeader()->setRcode(Header::RCODE_NOT_IMPLEMENTED);

            return Encoder::encodeMessage($responseMessage);
        }
    }

    /**
     * @param ResourceRecord[] $query
     */
    protected function isAuthoritative(array $query): bool
    {
        if (empty($query)) {
            return false;
        }

        $authoritative = true;
        foreach ($query as $rr) {
            $authoritative &= $this->resolver->isAuthority($rr->getName());
        }

        return $authoritative;
    }

    protected function needsAdditionalRecords(Message $message): void
    {
        foreach ($message->getAnswers() as $answer) {
            $name = null;
            switch ($answer->getType()) {
                case RecordTypeEnum::TYPE_NS:
                    $name = $answer->getRdata();
                    break;
                case RecordTypeEnum::TYPE_MX:
                    $name = $answer->getRdata()['exchange'];
                    break;
                case RecordTypeEnum::TYPE_SRV:
                    $name = $answer->getRdata()['target'];
                    break;
            }

            if (null === $name) {
                continue;
            }

            $query = [
                (new ResourceRecord())
                    ->setQuestion(true)
                    ->setType(RecordTypeEnum::TYPE_A)
                    ->setName($name),

                (new ResourceRecord())
                    ->setQuestion(true)
                    ->setType(RecordTypeEnum::TYPE_AAAA)
                    ->setName($name),
            ];

            foreach ($this->resolver->getAnswer($query) as $additional) {
                $message->addAdditional($additional);
            }
        }
    }

    public function getResolver(): ResolverInterface
    {
        return $this->resolver;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getIp(): string
    {
        return $this->ip;
    }
}
