<?php

namespace CatFerq\ReactPHPDNS\Services;

use CatFerq\ReactPHPDNS\Enums\RecordTypeEnum;
use InvalidArgumentException;

class RdataEncoder
{
    private static array $methodMap = [
        RecordTypeEnum::TYPE_A     => 'a',
        RecordTypeEnum::TYPE_AAAA  => 'a',
        RecordTypeEnum::TYPE_CNAME => 'cname',
        RecordTypeEnum::TYPE_DNAME => 'cname',
        RecordTypeEnum::TYPE_NS    => 'cname',
        RecordTypeEnum::TYPE_PTR   => 'cname',
        RecordTypeEnum::TYPE_SOA   => 'soa',
        RecordTypeEnum::TYPE_MX    => 'mx',
        RecordTypeEnum::TYPE_TXT   => 'txt',
        RecordTypeEnum::TYPE_SRV   => 'srv',
    ];

    public static function encodeRdata(int $type, $rdata): string
    {
        if (!array_key_exists($type, self::$methodMap)) {
            throw new UnsupportedTypeException(sprintf('Record type "%s" is not a supported type.', RecordTypeEnum::getName($type)));
        }

        return call_user_func(['self', self::$methodMap[$type]], $rdata);
    }

    public static function a(string $rdata): string
    {
        if (!filter_var($rdata, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(sprintf('The IP address "%s" is invalid.', $rdata));
        }

        return inet_pton($rdata);
    }

    public static function cname(string $rdata): string
    {
        return Encoder::encodeDomainName($rdata);
    }

    public static function soa(array $rdata): string
    {
        return
            Encoder::encodeDomainName($rdata['mname']) .
            Encoder::encodeDomainName($rdata['rname']) .
            pack(
                'NNNNN',
                $rdata['serial'],
                $rdata['refresh'],
                $rdata['retry'],
                $rdata['expire'],
                $rdata['minimum']
            );
    }

    public static function mx(array $rdata): string
    {
        return pack('n', (int)$rdata['preference']) . Encoder::encodeDomainName($rdata['exchange']);
    }

    public static function txt(string $rdata): string
    {
        $rdata = substr($rdata, 0, 255);

        return chr(strlen($rdata)) . $rdata;
    }

    public static function srv(array $rdata): string
    {
        return pack('nnn', (int)$rdata['priority'], (int)$rdata['weight'], (int)$rdata['port']) .
            Encoder::encodeDomainName($rdata['target']);
    }
}
