<?php

namespace CatFerq\ReactPHPDNS\Entities;

class Header
{
    public const OPCODE_STANDARD_QUERY = 0;

    public const OPCODE_INVERSE_QUERY = 1;

    public const OPCODE_STATUS_REQUEST = 2;

    public const RCODE_NO_ERROR = 0;

    public const RCODE_FORMAT_ERROR = 1;

    public const RCODE_SERVER_FAILURE = 2;

    public const RCODE_NAME_ERROR = 3;

    public const RCODE_NOT_IMPLEMENTED = 4;

    public const RCODE_REFUSED = 5;

    private int $id;

    private bool $response;

    private int $opcode;

    /**
     * AA.
     *
     * @var bool
     */
    private bool $authoritative;

    /**
     * TC.
     *
     * @var bool
     */
    private bool $truncated;

    /**
     * RD.
     *
     * @var bool
     */
    private bool $recursionDesired;

    /**
     * RA.
     *
     * @var bool
     */
    private bool $recursionAvailable;

    /**
     * A.
     *
     * @var int
     */
    private int $z = 0;

    /**
     * RCODE.
     *
     * @var int
     */
    private int $rcode;

    /**
     * QDCOUNT.
     *
     * @var int
     */
    private int $questionCount;

    /**
     * ANCOUNT.
     *
     * @var int
     */
    private int $answerCount;

    /**
     * NSCOUNT.
     *
     * @var int
     */
    private int $nameServerCount;

    /**
     * ARCOUNT.
     *
     * @var int
     */
    private int $additionalRecordsCount;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId($id): static
    {
        $this->id = (int)$id;

        return $this;
    }

    public function isQuery(): bool
    {
        return !$this->response;
    }

    public function isResponse(): bool
    {
        return $this->response;
    }

    public function setResponse($response): static
    {
        $this->response = (bool)$response;

        return $this;
    }

    public function setQuery($query): static
    {
        $this->response = !($query);

        return $this;
    }

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    public function setOpcode($opcode): static
    {
        $this->opcode = (int)$opcode;

        return $this;
    }

    public function isAuthoritative(): bool
    {
        return $this->authoritative;
    }

    public function setAuthoritative($authoritative): static
    {
        $this->authoritative = (bool)$authoritative;

        return $this;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function setTruncated($truncated): static
    {
        $this->truncated = (bool)$truncated;

        return $this;
    }

    public function isRecursionDesired(): bool
    {
        return $this->recursionDesired;
    }

    public function setRecursionDesired($recursionDesired): static
    {
        $this->recursionDesired = (bool)$recursionDesired;

        return $this;
    }

    public function isRecursionAvailable(): bool
    {
        return $this->recursionAvailable;
    }

    public function setRecursionAvailable($recursionAvailable): static
    {
        $this->recursionAvailable = (bool)$recursionAvailable;

        return $this;
    }

    public function getZ(): int
    {
        return $this->z;
    }

    public function setZ($z): static
    {
        $this->z = (int)$z;

        return $this;
    }

    public function getRcode(): int
    {
        return $this->rcode;
    }

    public function setRcode($rcode): static
    {
        $this->rcode = (int)$rcode;

        return $this;
    }

    public function getQuestionCount(): int
    {
        return $this->questionCount;
    }

    public function setQuestionCount($questionCount): static
    {
        $this->questionCount = (int)$questionCount;

        return $this;
    }

    public function getAnswerCount(): int
    {
        return $this->answerCount;
    }

    public function setAnswerCount($answerCount): static
    {
        $this->answerCount = (int)$answerCount;

        return $this;
    }

    public function getNameServerCount(): int
    {
        return $this->nameServerCount;
    }

    public function setNameServerCount($nameServerCount): static
    {
        $this->nameServerCount = (int)$nameServerCount;

        return $this;
    }

    public function getAdditionalRecordsCount(): int
    {
        return $this->additionalRecordsCount;
    }

    public function setAdditionalRecordsCount($additionalRecordsCount): static
    {
        $this->additionalRecordsCount = (int)$additionalRecordsCount;

        return $this;
    }
}
