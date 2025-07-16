<?php

namespace CatFerq\ReactPHPDNS\Entities;

use InvalidArgumentException;

class Message
{
    private Header $header;

    /** @var ResourceRecord[] */
    private array $questions = [];

    /** @var ResourceRecord[] */
    private array $answers = [];

    /** @var ResourceRecord[] */
    private array $authoritatives = [];

    /** @var ResourceRecord[] */
    private array $additionals = [];

    public function __construct(?Header $header = null)
    {
        if (null === $header) {
            $header = (new Header())
                ->setQuestionCount(0)
                ->setAnswerCount(0)
                ->setNameServerCount(0)
                ->setAdditionalRecordsCount(0);
        }

        $this->setHeader($header);
    }

    public function getHeader(): Header
    {
        return $this->header;
    }

    public function setHeader(Header $header): Message
    {
        $this->header = $header;

        return $this;
    }

    /**
     * @return ResourceRecord[]
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function setQuestions(array $resourceRecords): Message
    {
        $this->questions = [];
        foreach ($resourceRecords as $resourceRecord) {
            $this->addQuestion($resourceRecord);
        }

        return $this;
    }

    public function addQuestion(ResourceRecord $resourceRecord): Message
    {
        if (!$resourceRecord->isQuestion()) {
            throw new InvalidArgumentException('Resource Record provided is not a question.');
        }

        $this->questions[] = $resourceRecord;
        $this->header->setQuestionCount(count($this->questions));

        return $this;
    }

    /**
     * @return ResourceRecord[]
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function setAnswers(array $resourceRecords): Message
    {
        $this->answers = $resourceRecords;
        $this->header->setAnswerCount(count($this->answers));

        return $this;
    }

    public function addAnswer(ResourceRecord $resourceRecord): Message
    {
        $this->answers[] = $resourceRecord;
        $this->header->setAnswerCount(count($this->answers));

        return $this;
    }

    /**
     * @return ResourceRecord[]
     */
    public function getAuthoritatives(): array
    {
        return $this->authoritatives;
    }

    public function setAuthoritatives(array $resourceRecords): Message
    {
        $this->authoritatives = $resourceRecords;
        $this->header->setNameServerCount(count($this->authoritatives));

        return $this;
    }

    public function addAuthoritative(ResourceRecord $resourceRecord): Message
    {
        $this->authoritatives[] = $resourceRecord;
        $this->header->setNameServerCount(count($this->authoritatives));

        return $this;
    }

    /**
     * @return ResourceRecord[]
     */
    public function getAdditionals(): array
    {
        return $this->additionals;
    }

    public function setAdditionals(array $resourceRecords): Message
    {
        $this->additionals = $resourceRecords;
        $this->header->setAdditionalRecordsCount(count($this->additionals));

        return $this;
    }

    public function addAdditional(ResourceRecord $resourceRecord): Message
    {
        $this->additionals[] = $resourceRecord;
        $this->header->setAdditionalRecordsCount(count($this->additionals));

        return $this;
    }
}
