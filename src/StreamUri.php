<?php

namespace App;

final class StreamUri
{
    /** @var resource */
    private static $binding;

    /** @var resource */
    private $stream;

    /**
     * @param resource $stream
     * @param callable $callback
     * @return mixed
     */
    public static function cast($stream, callable $callback) : mixed
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('Invalid stream resource.');
        }

        if (!\stream_wrapper_register('anon', __CLASS__, \STREAM_IS_URL)) {
            throw new \RuntimeException(\error_get_last()['message'] ?? 'Failed to register stream wrapper "anon".');
        }

        self::$binding = $stream;

        try {
            $value = $callback('anon://' . \stream_get_meta_data($stream)['uri']);
        } finally {
            \stream_wrapper_unregister('anon');
            self::$binding = null;
        }

        return $value;
    }

    public function stream_open(string $path, string $mode, int $options) : bool
    {
        $this->stream = self::$binding;
        self::$binding = null;

        $isModeSupported = match ($mode) {
            'r', 'rb', 'rt' => true,
            default => false
        };

        $errorMessage = match (false) {
            $isModeSupported => \sprintf('Mode "%s" is not supported. Supported modes are "r", "rb" and "rt".', $mode),
            \is_resource($this->stream) => 'Invalid stream resource.',
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

    public function stream_read(int $count) : string|false
    {
        return \fread($this->stream, $count);
    }

    public function stream_tell() : int|false
    {
        return \ftell($this->stream);
    }

    public function stream_eof() : bool
    {
        return \feof($this->stream);
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET) : bool
    {
        return \fseek($this->stream, $offset, $whence) === 0;
    }

    public function stream_stat() : array
    {
        return \fstat($this->stream);
    }
}
