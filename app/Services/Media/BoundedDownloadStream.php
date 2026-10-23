<?php

namespace App\Services\Media;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use LogicException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Limit underlying R2 reads even when the archive library asks for larger blocks. */
final class BoundedDownloadStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public const CHUNK_BYTES = 65536;

    public function read($length): string
    {
        $data = $this->stream->read(min($length, self::CHUNK_BYTES));
        if ($data === '' && ! $this->stream->eof()) {
            throw new RuntimeException('Download source stalled.');
        }

        return $data;
    }

    public function getContents(): string
    {
        throw new LogicException('Bulk download streams must not be buffered.');
    }

    public function __toString(): string
    {
        throw new LogicException('Bulk download streams must not be buffered.');
    }
}
