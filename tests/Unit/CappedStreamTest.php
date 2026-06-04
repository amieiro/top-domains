<?php

namespace Tests\Unit;

use App\Support\CappedStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class CappedStreamTest extends TestCase
{
    private function capped(int $cap): CappedStream
    {
        return new CappedStream(Utils::streamFor(''), $cap);
    }

    public function test_keeps_only_the_first_cap_bytes(): void
    {
        $stream = $this->capped(5);
        $stream->write('hello world');
        $stream->rewind();

        $this->assertSame('hello', $stream->getContents());
    }

    public function test_reports_full_length_written_so_curl_continues(): void
    {
        // curl aborts the transfer (error 23) unless the write callback returns
        // the full number of bytes it was handed.
        $stream = $this->capped(5);

        $this->assertSame(11, $stream->write('hello world'));
    }

    public function test_caps_across_multiple_writes(): void
    {
        $stream = $this->capped(8);
        $stream->write('abcde');
        $stream->write('fghij');
        $stream->rewind();

        $this->assertSame('abcdefgh', (string) $stream);
    }

    public function test_under_cap_content_is_preserved(): void
    {
        $stream = $this->capped(100);
        $stream->write('short body');
        $stream->rewind();

        $this->assertSame('short body', $stream->getContents());
    }
}
