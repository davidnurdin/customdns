<?php

namespace CatFerq\ReactPHPDNS\Services;

use CatFerq\ReactPHPDNS\Exceptions\UnsupportedTypeException;
use CatFerq\ReactPHPDNS\Enums\RecordTypeEnum;

class RdataDecoder
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

    public static function decodeRdata(int $type, string $rdata)
    {
        if (!array_key_exists($type, self::$methodMap)) {
            throw new UnsupportedTypeException(sprintf('Record type "%s" is not a supported type.', RecordTypeEnum::getName($type)));
        }

        return call_user_func(['self', self::$methodMap[$type]], $rdata);
    }

    public static function a(string $rdata): string
    {
        return inet_ntop($rdata);
    }

    public static function cname(string $rdata): string
    {
        return Decoder::decodeDomainName($rdata);
    }

    public static function soa(string $rdata): array
    {
        $offset = 0;

        return array_merge(
            [
                'mname' => Decoder::decodeDomainName($rdata, $offset),
                'rname' => Decoder::decodeDomainName($rdata, $offset),
            ],
            unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', substr($rdata, $offset))
        );
    }

    public static function mx(string $rdata): array
    {
        return [
            'preference' => unpack('npreference', $rdata)['preference'],
            'exchange'   => Decoder::decodeDomainName(substr($rdata, 2)),
        ];
    }

    public static function txt(string $rdata): string
    {
        $len = ord($rdata[0]);
        if ((strlen($rdata) + 1) < $len) {
            return '';
        }

        return substr($rdata, 1, $len);
    }

    public static function srv(string $rdata): array
    {
        $offset           = 6;
        $values           = unpack('npriority/nweight/nport', $rdata);
        $values['target'] = Decoder::decodeDomainName($rdata, $offset);

        return $values;
    }
}
