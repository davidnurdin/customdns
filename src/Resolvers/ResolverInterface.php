<?php

namespace CatFerq\ReactPHPDNS\Resolvers;

use CatFerq\ReactPHPDNS\Entities\ResourceRecord;

interface ResolverInterface
{
    /**
     * Return answer for given query.
     *
     * @param ResourceRecord[] $queries
     *
     * @return ResourceRecord[]
     */
    public function getAnswer(array $queries, ?string $client = null): array;

    /**
     * Returns true if resolver supports recursion.
     *
     * @return bool
     */
    public function allowsRecursion(): bool;

    /**
     * Check if the resolver knows about a domain.
     * Returns true if the resolver holds info about $domain.
     *
     * @param string $domain The domain to check for
     *
     * @return bool
     */
    public function isAuthority(string $domain): bool;
}
