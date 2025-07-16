<?php

namespace CatFerq\ReactPHPDNS\Resolvers;

use Closure;

class ClosureResolver implements ResolverInterface
{
    public function __construct(
        private readonly Closure $closure
    )
    {
    }

    public function getAnswer(array $queries, ?string $client = null): array
    {
        $answers = [];
        foreach ($queries as $query) {
            $answer  = ($this->closure)($query);
            $answers = array_merge($answers, $answer);
        }

        return $answers;
    }

    public function allowsRecursion(): bool
    {
        return false;
    }

    public function isAuthority(string $domain): bool
    {
        return true;
    }
}