<?php

declare(strict_types=1);

namespace ImaginaPay\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
