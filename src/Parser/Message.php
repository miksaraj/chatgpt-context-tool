<?php

declare(strict_types=1);

namespace ChatGPTContext\Parser;

final class Message
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly float $timestamp,
    ) {}
}
