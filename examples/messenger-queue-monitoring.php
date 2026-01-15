<?php

declare(strict_types=1);

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

// Dispatch side (controller/service)
final class SendEmailCommand
{
    public function __construct(public string $to, public string $body)
    {
    }
}

final class SendEmailController
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(): void
    {
        $this->bus->dispatch(new SendEmailCommand('user@example.com', 'Hello!'));
    }
}

// Worker side
final class SendEmailHandler implements MessageHandlerInterface
{
    public function __invoke(SendEmailCommand $command): void
    {
        echo sprintf("Send mail to %s with body %s\n", $command->to, $command->body);
    }
}
