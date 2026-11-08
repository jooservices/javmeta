<?php

declare(strict_types=1);

namespace Tests\E2E;

use Tests\E2E\Support\ApiKeyCredential;
use Tests\E2E\Support\CatalogSeed;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\FixtureSeed;

/** Immutable state shared by the steps in one end-to-end journey. */
final readonly class Context
{
    /**
     * @param array<string, mixed>|null $lastRequest
     * @param array<string, mixed>|null $lastResponse
     * @param list<ApiKeyCredential> $keysCreated
     */
    public function __construct(
        public string $baseUrl,
        public string $ownerToken,
        public ?array $lastRequest = null,
        public ?array $lastResponse = null,
        public array $keysCreated = [],
        public ?CatalogSeed $catalog = null,
        public ?FixtureSeed $fixture = null,
        public ?CrawlResult $crawl = null,
    ) {}

    /** @param array<string, mixed> $request @param array<string, mixed>|null $response */
    public function withExchange(array $request, ?array $response): self
    {
        return new self($this->baseUrl, $this->ownerToken, $request, $response, $this->keysCreated, $this->catalog, $this->fixture, $this->crawl);
    }

    public function withCreatedKey(ApiKeyCredential $credential): self
    {
        return new self($this->baseUrl, $this->ownerToken, $this->lastRequest, $this->lastResponse, [...$this->keysCreated, $credential], $this->catalog, $this->fixture, $this->crawl);
    }

    public function key(string $purpose = 'consumer'): ?ApiKeyCredential
    {
        foreach (array_reverse($this->keysCreated) as $credential) {
            if ($credential->purpose === $purpose) {
                return $credential;
            }
        }

        return null;
    }

    public function withCatalog(CatalogSeed $catalog): self
    {
        return new self($this->baseUrl, $this->ownerToken, $this->lastRequest, $this->lastResponse, $this->keysCreated, $catalog, $this->fixture, $this->crawl);
    }

    public function withFixture(FixtureSeed $fixture): self
    {
        return new self($this->baseUrl, $this->ownerToken, $this->lastRequest, $this->lastResponse, $this->keysCreated, $this->catalog, $fixture, $this->crawl);
    }

    public function withCrawl(CrawlResult $crawl): self
    {
        return new self($this->baseUrl, $this->ownerToken, $this->lastRequest, $this->lastResponse, $this->keysCreated, $this->catalog, $this->fixture, $crawl);
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return [$this->ownerToken, ...array_map(static fn(ApiKeyCredential $key): string => $key->plaintext, $this->keysCreated)];
    }
}
