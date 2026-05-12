<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/**
 * In sequence attempt to find Fully Qualified Domain Name (FQDN) when not
 * explicitly queried for, and when the amount of dots in the name is equal
 * or greater than the passed ndots parameter.
 *
 * Wraps an existing `ExecutorInterface` to do the actual querying and only
 * concerns itself with working through the list of search domains until a
 * matching answer comes back from the resolver.
 *
 * This might cause a delay for domains that are never meant to be search
 * such as public domains like exampled.com, as such always query with a
 * FQDN when searching isn't required.
 *
 * This is useful in situations like Kubernetes where you might only know
 * a services name and namespace but don't know the rest of the clusters
 * LOOK UP svc.cluster.local and want to rely on the resolve.conf injected
 * into pods.
 *
 * This is a high level executor, and it should be placed between the
 * RetryExecutor and the CoopExecutor. So transient networking issues
 * are handled through the RetryExecutor and other low level executors.
 * And no duplicated queries are sent out with CoopExecutor.
 *
 * ```php
 * $executor = new CoopExecutor(
 *     new SearchingExecutor(
 *         new RetryExecutor(
 *             new TimeoutExecutor(
 *                 new UdpTransportExecutor($nameserver),
 *                 3.0
 *             )
 *         ),
 *         5,
 *         'svc',
 *         'svc.cluster',
 *         'svc.cluster.local'
 *     )
 * );
 * ```
 */
final class SearchingExecutor implements ExecutorInterface
{
    /**
     * @var ExecutorInterface
     */
    private $executor;
    /**
     * @var int
     */
    private $ndots;
    /**
     * @var array<string>
     */
    private $domains;

    public function __construct(ExecutorInterface $base, int $ndots, string $firstDomain, string ...$domains)
    {
        $this->executor = $base;
        $this->ndots = $ndots;

        array_unshift($domains, $firstDomain);
        $this->domains = $domains;
    }

    public function query(Query $query)
    {
        if (substr($query->name, -1) === '.') {
            return $this->executor->query(new Query(
                substr($query->name, 0, -1),
                $query->type,
                $query->class
            ));
        }

        $startWithAbsolute = substr_count($query->name, '.') >= $this->ndots;
        $domains = [];
        if ($startWithAbsolute === true) {
            $domains[] = $query->name;
        }
        foreach ($this->domains as $domain) {
            $domains[] = $query->name . '.' . $domain;
        }
        if ($startWithAbsolute === false) {
            $domains[] = $query->name;
        }

        $firstDomain = array_shift($domains);
        $seeker = function (Message $message) use ($query, &$seeker, &$domains): PromiseInterface {
            if ($this->hasRecords($message, $query)) {
                return resolve($message);
            }


            $promise = $this->executor->query(new Query(
                array_shift($domains),
                $query->type,
                $query->class
            ));

            if (count($domains) > 0) {
                $promise = $promise->then($seeker);
            }

            return $promise;
        };

        return $this->executor->query(new Query(
            $firstDomain,
            $query->type,
            $query->class
        ))->then($seeker);
    }

    private function hasRecords(Message $message, Query $query): bool
    {
        foreach ($message->answers as $record) {
            if ($record->type === $query->type && $record->class === $query->class) {
                return true;
            }
        }

        return false;
    }
}
