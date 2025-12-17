<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class RabbitMqConnectionFactory
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $vhost = '/',
    ) {
    }

    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass, $this->vhost);
    }
}
