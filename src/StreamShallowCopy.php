<?php

namespace App;

use function Safe\fopen;

final class StreamShallowCopy
{
    /** @var resource */
    public $context;

    /** @var resource */
    private $stream;
    private int $position;
    private bool $eof;

    /**
     * @param resource $stream
     * @return resource
     */
    public static function create($stream)
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Invalid stream resource.');
        }

        $metadata = \stream_get_meta_data($stream);

        if (!$metadata['seekable']) {
            throw new \InvalidArgumentException('Stream is not seekable.');
        }

        if (!\stream_wrapper_register('shallow', __CLASS__, \STREAM_IS_URL)) {
            throw new \RuntimeException(\error_get_last()['message'] ?? 'Failed to register stream wrapper "shallow".');
        }

        try {
            return fopen(
                'shallow://' . $metadata['uri'],
                'r', false,
                \stream_context_create([
                    'shallow' => ['stream' => $stream]
                ])
            );
        } finally {
            \stream_wrapper_unregister('shallow');
        }
    }

    public function stream_open(string $path, string $mode, int $options) : bool
    {
        $context = \stream_context_get_options($this->context);

        $errorMessage = match (false) {
            $mode === 'r' => \sprintf('Mode "%s" is not supported. Only "r" is supported.', $mode),
            \is_resource($this->stream = $context['shallow']['stream'] ?? null) => 'Invalid stream resource.',
            $this->stream_seek(0) => 'Rewind failed.',
            default => ''
        };

        if ($errorMessage === '') {
            return true;
        }

        if ($options & \STREAM_REPORT_ERRORS) {
            trigger_error($errorMessage, \E_USER_WARNING);
        }

        return false;
    }

    public function stream_tell() : int
    {
        return $this->position;
    }

    public function stream_eof() : bool
    {
        return $this->eof;
    }

    public function stream_read(int $count) : string|false
    {
        // Restore the position if it has been changed
        if (\ftell($this->stream) === $this->position || \fseek($this->stream, $this->position) === 0) {
            $data = \fread($this->stream, $count);
            $this->position = \ftell($this->stream);
            $this->eof = \feof($this->stream);

            return $data;
        }

        $this->eof = true;

        return false;
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET) : bool
    {
        if (\fseek($this->stream, $offset, $whence) === 0) {
            $this->position = \ftell($this->stream);
            $this->eof = \feof($this->stream);

            return true;
        }

        $this->eof = true;

        return false;
    }

    public function stream_stat() : array
    {
        return \fstat($this->stream);
    }
}