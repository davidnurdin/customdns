<?php

namespace CatFerq\ReactPHPDNS\Entities;

use CatFerq\ReactPHPDNS\Enums\ClassEnum;
use CatFerq\ReactPHPDNS\Enums\RecordTypeEnum;

class ResourceRecord
{
    private string $name;

    private int $type;

    private int $ttl;

    private string|array $rdata;

    private int $class = ClassEnum::INTERNET;

    private bool $question = false;

    public function getType(): int
    {
        return $this->type;
    }

    public function setType(int $type): ResourceRecord
    {
        $this->type = $type;

        return $this;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function setTtl(int $ttl): ResourceRecord
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function getRdata(): array|string
    {
        return $this->rdata;
    }

    public function setRdata(array|string $rdata): ResourceRecord
    {
        $this->rdata = $rdata;

        return $this;
    }

    public function getClass(): int
    {
        return $this->class;
    }

    public function setClass(int $class): ResourceRecord
    {
        $this->class = $class;

        return $this;
    }

    public function isQuestion(): bool
    {
        return $this->question;
    }

    public function setQuestion(bool $question): ResourceRecord
    {
        $this->question = $question;

        return $this;
    }

    public function __toString()
    {
        if (is_array($this->rdata)) {
            $rdata = '(';
            foreach ($this->rdata as $key => $value) {
                $rdata .= $key . ': ' . $value . ', ';
            }
            $rdata = rtrim($rdata, ', ') . ')';
        } else {
            $rdata = $this->rdata;
        }

        return sprintf(
            '%s %s %s %s %s',
            $this->name,
            RecordTypeEnum::getName($this->type),
            ClassEnum::getName($this->class),
            $this->ttl,
            $rdata
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): ResourceRecord
    {
        $this->name = $name;

        return $this;
    }
}
