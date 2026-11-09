<?php

namespace App\Sources\Connectors;

use App\Sources\JobSource;
use App\Sources\SourceFetchFailed;
use App\Sources\SourceHttp;
use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Throwable;

abstract class Connector implements JobSource
{
    /** @var string[] */
    private array $partialFailures = [];

    public function __construct(protected SourceHttp $http, protected array $options = []) {}

    public function partialFailures(): array
    {
        return $this->partialFailures;
    }

    protected function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function feed(string $url): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET keeps a hostile feed from pulling external entities.
        $xml = simplexml_load_string($this->http->text($url), SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new SourceFetchFailed('Feed could not be parsed: '.parse_url($url, PHP_URL_HOST));
        }

        return $xml;
    }

    /**
     * Runs one request per item (query or company board) so that a single dead
     * board does not take the rest down. Fails only when every item failed.
     *
     * @return iterable<mixed> results of $call, in order
     */
    protected function eachTolerant(array $items, callable $call): iterable
    {
        $failures = [];

        foreach ($items as $item) {
            try {
                yield $call($item);
            } catch (SourceFetchFailed $e) {
                $failures[] = $e->getMessage();
            }
        }

        if ($items !== [] && count($failures) === count($items)) {
            throw new SourceFetchFailed(implode('; ', array_unique($failures)));
        }

        $this->partialFailures = array_values(array_unique($failures));
    }
}
