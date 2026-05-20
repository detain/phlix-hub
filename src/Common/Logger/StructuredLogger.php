<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Structured logger built on top of Monolog 3.
 *
 * Wraps a {@see Logger} with channel-aware configuration. Each instance
 * is bound to one log channel and writes to the handlers configured in
 * `config/logger.php`. The class is intentionally similar to the
 * `phlix-server` version so behaviour matches across the two repos.
 *
 * @package Phlix\Hub\Common\Logger
 * @since 0.1.0
 */
class StructuredLogger
{
    private Logger $logger;

    private string $channel;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * Build a logger for the given channel using the loaded logger config.
     *
     * @param string               $channel Channel name (see {@see LogChannels}).
     * @param array<string, mixed> $config  The loaded logger config array.
     *
     * @since 0.1.0
     */
    public function __construct(string $channel, array $config)
    {
        $this->channel = $channel;
        $this->config = $config;
        $this->logger = new Logger($channel);

        $this->setupHandlers();
        $this->setupProcessors();
    }

    /**
     * Pushes one handler per entry in the `handlers` config slice.
     *
     * @return void
     */
    private function setupHandlers(): void
    {
        /** @var array<string, array<string, mixed>> $handlers */
        $handlers = $this->config['handlers'] ?? [];
        foreach ($handlers as $handlerConfig) {
            $handler = $this->createHandler($handlerConfig);
            $this->logger->pushHandler($handler);
        }
    }

    /**
     * Create a Monolog handler from a config slice.
     *
     * @param array<string, mixed> $config Handler config.
     *
     * @return HandlerInterface The instantiated handler.
     */
    private function createHandler(array $config): HandlerInterface
    {
        /** @var mixed $typeRaw */
        $typeRaw = $config['type'] ?? 'rotating_file';
        $type = is_string($typeRaw) ? $typeRaw : 'rotating_file';

        /** @var mixed $pathRaw */
        $pathRaw = $config['path'] ?? 'php://stdout';
        $path = is_string($pathRaw) ? $pathRaw : 'php://stdout';

        /** @var mixed $maxFilesRaw */
        $maxFilesRaw = $config['max_files'] ?? 30;
        $maxFiles = is_int($maxFilesRaw) ? $maxFilesRaw : 30;

        /** @var mixed $levelRaw */
        $levelRaw = $config['level'] ?? 'debug';
        $level = $this->mapLevel(is_string($levelRaw) ? $levelRaw : 'debug');

        return match ($type) {
            'rotating_file' => new RotatingFileHandler($path, $maxFiles, $level),
            'stream' => new StreamHandler($path, $level),
            default => new StreamHandler('php://stdout', Level::Debug),
        };
    }

    /**
     * Register the standard log processors (PSR message + request id).
     *
     * @return void
     */
    private function setupProcessors(): void
    {
        $this->logger->pushProcessor(new PsrLogMessageProcessor());
    }

    /**
     * Map a string level to the matching Monolog {@see Level}.
     *
     * @param string $level Case-insensitive level name.
     *
     * @return Level The mapped Monolog level (defaults to INFO).
     */
    private function mapLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning', 'warn' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    /**
     * Emergency log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(Level::Emergency, $message, $context);
    }

    /**
     * Alert log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(Level::Alert, $message, $context);
    }

    /**
     * Critical log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /**
     * Error log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    /**
     * Warning log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    /**
     * Notice log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    /**
     * Info log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    /**
     * Debug log.
     *
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    /**
     * Log at an arbitrary level. Auto-tags context with the channel name.
     *
     * @param Level                $level   Monolog level.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Context.
     */
    public function log(Level $level, string $message, array $context = []): void
    {
        $context['channel'] = $this->channel;
        $this->logger->log($level, $message, $context);
    }
}
