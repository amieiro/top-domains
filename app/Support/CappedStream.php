<?php

namespace App\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A write-capping stream decorator. It stores at most a fixed number of bytes
 * (discarding the rest) while still reporting the full write length, so it can
 * be used as a Guzzle `sink`: curl keeps streaming the response, but the body
 * never grows past the cap and never spills to a temporary file on disk.
 */
class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** The decorated, in-memory stream. Declared to avoid a dynamic property. */
    private StreamInterface $stream;

    /** Remaining bytes we are still willing to store. */
    private int $remaining;

    public function __construct(StreamInterface $stream, int $cap)
    {
        $this->stream = $stream;
        $this->remaining = max(0, $cap);
    }

    public function write(string $string): int
    {
        $length = strlen($string);

        if ($this->remaining > 0) {
            $chunk = substr($string, 0, $this->remaining);
            $this->stream->write($chunk);
            $this->remaining -= strlen($chunk);
        }

        // Report the full length so curl does not treat this as a short write
        // and abort the transfer.
        return $length;
    }
}
