<?php

namespace CatFerq\ReactPHPDNS\Services;

use CatFerq\ReactPHPDNS\Entities\Header;
use CatFerq\ReactPHPDNS\Entities\Message;
use CatFerq\ReactPHPDNS\Entities\ResourceRecord;
use CatFerq\ReactPHPDNS\Exceptions\UnsupportedTypeException;

class Encoder
{
    /**
     * @throws UnsupportedTypeException
     */
    public static function encodeMessage(Message $message): string
    {
        return
            self::encodeHeader($message->getHeader()) .
            self::encodeResourceRecords($message->getQuestions()) .
            self::encodeResourceRecords($message->getAnswers()) .
            self::encodeResourceRecords($message->getAuthoritatives()) .
            self::encodeResourceRecords($message->getAdditionals());
    }

    public static function encodeHeader(Header $header): string
    {
        return pack(
            'nnnnnn',
            $header->getId(),
            self::encodeFlags($header),
            $header->getQuestionCount(),
            $header->getAnswerCount(),
            $header->getNameServerCount(),
            $header->getAdditionalRecordsCount()
        );
    }

    /**
     * @param ResourceRecord[] $resourceRecords
     *
     * @throws UnsupportedTypeException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public static function encodeResourceRecords(array $resourceRecords): string
    {
        $records = array_map('self::encodeResourceRecord', $resourceRecords);

        return implode('', $records);
    }

    private static function encodeFlags(Header $header): int
    {
        return 0x0 |
            ($header->isResponse() & 0x1) << 15 |
            ($header->getOpcode() & 0xf) << 11 |
            ($header->isAuthoritative() & 0x1) << 10 |
            ($header->isTruncated() & 0x1) << 9 |
            ($header->isRecursionDesired() & 0x1) << 8 |
            ($header->isRecursionAvailable() & 0x1) << 7 |
            ($header->getZ() & 0x7) << 4 |
            ($header->getRcode() & 0xf);
    }

    /**
     * @throws UnsupportedTypeException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public static function encodeResourceRecord(ResourceRecord $rr): string
    {
        $encoded = self::encodeDomainName($rr->getName());
        if ($rr->isQuestion()) {
            return $encoded . pack('nn', $rr->getType(), $rr->getClass());
        }

        $data    = RdataEncoder::encodeRdata($rr->getType(), $rr->getRdata());
        $encoded .= pack('nnNn', $rr->getType(), $rr->getClass(), $rr->getTtl(), strlen($data));

        return $encoded . $data;
    }

    public static function encodeDomainName($domain): string
    {
        if ('.' === $domain) {
            return chr(0);
        }

        $domain = rtrim($domain, '.') . '.';
        $res    = '';

        foreach (explode('.', $domain) as $label) {
            $res .= chr(strlen($label)) . $label;
        }

        return $res;
    }
}
