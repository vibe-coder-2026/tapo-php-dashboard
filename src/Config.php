<?php

declare(strict_types=1);

namespace Tapo;

class Config
{
    private function __construct(
        private readonly string $email,
        private readonly string $password,
    ) {}

    public static function fromEnvFile(string $path): self
    {
        $env = parse_ini_file($path);
        return new self(
            (string) ($env['TP_LINK_TAPO_USER'] ?? ''),
            (string) ($env['TP_LINK_TAPO_PASS'] ?? ''),
        );
    }

    public function email(): string { return $this->email; }
    public function password(): string { return $this->password; }
}
