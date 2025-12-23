<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMQ;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Yiisoft\Log\Logger as YiisoftLogger;

final class RabbitMqService
{
    public const EXCHANGE = 'tg.direct';
    public const Q_UPDATES = 'tg_got_data';
    public const RK_PROFILE_PROMPT = 'tg.profile_prompt';
    public const Q_PROFILE_PROMPT = 'tg.profile_prompt';
    public const Q_PROFILE_PROMPT_DELAY = 'tg.profile_prompt.delay';
    public const RK_PUSH = 'tg.pushes';
    public const Q_PUSH = 'tg.pushes';

    public function __construct(
        private readonly RabbitMqConnectionFactory $factory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function withChannel(callable $cb): void
    {
        $conn = $this->factory->create();
        try {
            $ch = $conn->channel();
            try {
                $cb($ch);
            } finally {
                $ch->close();
            }
        } finally {
            $conn->close();
        }
    }

    public function ensureTopology(): void
    {
        $this->withChannel(function (AMQPChannel $ch): void {
            // Main direct exchange for routing
            $ch->exchange_declare(self::EXCHANGE, AMQPExchangeType::DIRECT, false, true, false);

            // Queue for profile prompt (to be consumed by sender)
            $ch->queue_declare(self::Q_PROFILE_PROMPT, false, true, false, false);
            $ch->queue_bind(self::Q_PROFILE_PROMPT, self::EXCHANGE, self::RK_PROFILE_PROMPT);

            // Delay queue with dead-letter back to main exchange
            $args = new AMQPTable([
                'x-dead-letter-exchange' => self::EXCHANGE,
                'x-dead-letter-routing-key' => self::RK_PROFILE_PROMPT,
            ]);
            $ch->queue_declare(self::Q_PROFILE_PROMPT_DELAY, false, true, false, false, false, $args);

            // Updates queue is usually pre-declared by publisher, but ensure exists
            $ch->queue_declare(self::Q_UPDATES, false, true, false, false);

            // Dedicated pushes queue (prebuilt messages)
            $ch->queue_declare(self::Q_PUSH, false, true, false, false);
            $ch->queue_bind(self::Q_PUSH, self::EXCHANGE, self::RK_PUSH);
        });
    }

    public function consumeUpdates(callable $handler, int $memoryLimit = 0, int $messagesLimit = 0): void
    {
        $conn = $this->factory->create();
        $ch = $conn->channel();
        $ch->basic_qos(null, 1, null);
        $processedMessages = 0;
        $ch->basic_consume(self::Q_UPDATES, '', false, false, false, false, function (AMQPMessage $msg) use ($handler, &$processedMessages): void {
            try {
                $body = $msg->getBody();
                $update = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($update)) {
                    throw new \RuntimeException('Telegram update is not an object/array');
                }

                // Extract unix_time from AMQP headers for logging context (new format)
                $props = $msg->get_properties();
                $headers = $props['application_headers'] ?? null;
                $ctx = [];
                if ($headers instanceof AMQPTable) {
                    $tbl = $headers->getNativeData();
                    $unixTime = $tbl['unix_time'] ?? null;
                    if (is_int($unixTime) || (is_string($unixTime) && ctype_digit($unixTime))) {
                        $ctx['unix_time'] = (int) $unixTime;
                    }
                }

                $this->logger->debug('Consumed Telegram update', $ctx);
                $handler($update);
                $msg->ack();
            } catch (\Throwable $e) {
                // Log with exception context and file:line for better diagnostics
                $this->logger->error(
                    'Failed to handle Telegram update: {err} @ {file}:{line}',
                    [
                        'err' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'exception' => $e,
                        // keep preview small to avoid exceeding Telegram limits
                        'payload_preview' => is_string($msg->getBody()) ? substr($msg->getBody(), 0, 500) : null,
                    ]
                );
                if ($this->logger instanceof YiisoftLogger) {
                    // Force immediate export from long-running consumer
                    $this->logger->flush(true);
                }
                $msg->nack(false, false); // drop message to avoid infinite loop
            } finally {
                $processedMessages++;
            }
        });

        while ($ch->is_consuming()) {
            $ch->wait();
            if ($messagesLimit > 0 && $processedMessages >= $messagesLimit) {
                $this->logger->info("Message limit reached ({$messagesLimit}), stopping consumer.");
                break;
            }
            if ($memoryLimit > 0 && (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit) {
                $this->logger->info("Memory limit reached (" . round(memory_get_usage(true) / 1024 / 1024, 2) . "MB >= {$memoryLimit}MB), stopping consumer.");
                break;
            }
        }
        $ch->close();
        $conn->close();
    }

    public function consumeProfilePrompt(callable $handler, int $memoryLimit = 0, int $messagesLimit = 0): void
    {
        $conn = $this->factory->create();
        $ch = $conn->channel();
        $ch->basic_qos(null, 1, null);
        $processedMessages = 0;
        $ch->basic_consume(self::Q_PROFILE_PROMPT, '', false, false, false, false, function (AMQPMessage $msg) use ($handler, &$processedMessages): void {
            try {
                $payload = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $handler($payload);
                $msg->ack();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to handle profile prompt: {err} @ {file}:{line}',
                    [
                        'err' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'exception' => $e,
                        'payload_preview' => is_string($msg->getBody()) ? substr($msg->getBody(), 0, 500) : null,
                    ]
                );
                if ($this->logger instanceof YiisoftLogger) {
                    $this->logger->flush(true);
                }
                $msg->nack(false, false);
            } finally {
                $processedMessages++;
            }
        });
        while ($ch->is_consuming()) {
            $ch->wait();
            if ($messagesLimit > 0 && $processedMessages >= $messagesLimit) {
                $this->logger->info("Message limit reached ({$messagesLimit}), stopping consumer.");
                break;
            }
            if ($memoryLimit > 0 && (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit) {
                $this->logger->info("Memory limit reached (" . round(memory_get_usage(true) / 1024 / 1024, 2) . "MB >= {$memoryLimit}MB), stopping consumer.");
                break;
            }
        }
        $ch->close();
        $conn->close();
    }

    public function publishProfilePromptDelayed(array $payload, int $delayMs): void
    {
        $this->withChannel(function (AMQPChannel $ch) use ($payload, $delayMs): void {
            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'expiration' => (string) max(0, $delayMs),
            ]);
            $ch->basic_publish($msg, '', self::Q_PROFILE_PROMPT_DELAY);
        });
    }

    public function publishProfilePrompt(array $payload): void
    {
        $this->withChannel(function (AMQPChannel $ch) use ($payload): void {
            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $ch->basic_publish($msg, self::EXCHANGE, self::RK_PROFILE_PROMPT);
        });
    }

    public function publishPush(array $payload): void
    {
        $this->withChannel(function (AMQPChannel $ch) use ($payload): void {
            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $ch->basic_publish($msg, self::EXCHANGE, self::RK_PUSH);
        });
    }

    public function consumePushes(callable $handler, int $memoryLimit = 0, int $messagesLimit = 0): void
    {
        $conn = $this->factory->create();
        $ch = $conn->channel();
        $ch->basic_qos(null, 1, null);
        $processedMessages = 0;
        $ch->basic_consume(self::Q_PUSH, '', false, false, false, false, function (AMQPMessage $msg) use ($handler, &$processedMessages): void {
            try {
                $payload = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $handler($payload);
                $msg->ack();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to handle push: {err} @ {file}:{line}',
                    [
                        'err' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'exception' => $e,
                        'payload_preview' => is_string($msg->getBody()) ? substr($msg->getBody(), 0, 500) : null,
                    ]
                );
                if ($this->logger instanceof YiisoftLogger) {
                    $this->logger->flush(true);
                }
                $msg->nack(false, false);
            } finally {
                $processedMessages++;
            }
        });
        while ($ch->is_consuming()) {
            $ch->wait();
            if ($messagesLimit > 0 && $processedMessages >= $messagesLimit) {
                $this->logger->info("Message limit reached ({$messagesLimit}), stopping consumer.");
                break;
            }
            if ($memoryLimit > 0 && (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit) {
                $this->logger->info("Memory limit reached (" . round(memory_get_usage(true) / 1024 / 1024, 2) . "MB >= {$memoryLimit}MB), stopping consumer.");
                break;
            }
        }
        $ch->close();
        $conn->close();
    }
}
