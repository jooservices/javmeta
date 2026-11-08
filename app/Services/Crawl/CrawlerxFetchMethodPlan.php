<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use JOOservices\CrawlerX\Dto\AdapterManifestDto;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\FetchChainDto;
use JOOservices\CrawlerX\Dto\FetchOptionsDto;
use JOOservices\CrawlerX\Dto\SiteProfileDto;
use JOOservices\CrawlerX\Enums\FetchMethod;
use JOOservices\CrawlerX\Registry\FileAdapterManifestRegistry;

final class CrawlerxFetchMethodPlan
{
    private static ?FileAdapterManifestRegistry $manifests = null;

    public function __construct(private readonly FetchMethodPreference $preference) {}

    public function optionsFor(string $sourceSlug, string $url, int $deadlineSeconds): CrawlOptionsDto
    {
        $fetchOptions = new FetchOptionsDto(deadlineSeconds: max(1, $deadlineSeconds));
        $host = parse_url($url, PHP_URL_HOST);
        $manifest = $this->manifests()->get($sourceSlug);

        if (! is_string($host) || ! $manifest instanceof AdapterManifestDto) {
            return new CrawlOptionsDto(fetch: $fetchOptions);
        }

        $decision = $this->preference->beforeFetch($sourceSlug, $host);
        if (! $decision->prefersFlaresolverr) {
            return new CrawlOptionsDto(fetch: $fetchOptions);
        }

        $chain = SiteProfileDto::fromManifest($manifest)->fetchChain;
        if (! $this->hasBrowserMethods($chain)) {
            return new CrawlOptionsDto(fetch: $fetchOptions);
        }

        return new CrawlOptionsDto(fetch: new FetchOptionsDto(
            chain: new FetchChainDto($this->orderedChain($chain, $decision->probePlaywright)),
            deadlineSeconds: max(1, $deadlineSeconds),
        ));
    }

    /** @param array<string, mixed> $profile */
    public function retryOptionsFor(string $sourceSlug, string $url, int $deadlineSeconds, array $profile): CrawlOptionsDto
    {
        $preferred = (array) ($profile['preferred_methods'] ?? []);
        $hasProxy = is_string($profile['http_proxy_url'] ?? null) && $profile['http_proxy_url'] !== '';
        if ($preferred === [] && ! $hasProxy) {
            return $this->optionsFor($sourceSlug, $url, $deadlineSeconds);
        }

        $manifest = $this->manifests()->get($sourceSlug);
        if (! $manifest instanceof AdapterManifestDto) {
            return new CrawlOptionsDto(fetch: new FetchOptionsDto(deadlineSeconds: max(1, $deadlineSeconds)));
        }

        $normalOptions = $this->optionsFor($sourceSlug, $url, $deadlineSeconds);
        $chain = $normalOptions->fetch?->chain->methods ?? SiteProfileDto::fromManifest($manifest)->fetchChain;
        if ($hasProxy && ! in_array(FetchMethod::Http, $chain, true)) {
            $chain[] = FetchMethod::Http;
        }
        $requested = [];
        foreach ($preferred as $value) {
            $method = is_string($value) ? FetchMethod::tryFrom($value) : null;
            if ($method instanceof FetchMethod && in_array($method, $chain, true)) {
                $requested[] = $method;
            }
        }
        $ordered = [...$requested, ...array_values(array_filter($chain, static fn(FetchMethod $method): bool => ! in_array($method, $requested, true)))];

        return new CrawlOptionsDto(fetch: new FetchOptionsDto(
            chain: new FetchChainDto($ordered),
            deadlineSeconds: max(1, $deadlineSeconds),
        ));
    }

    /** @param list<array<string, mixed>> $attempts */
    public function recordAttempts(string $sourceSlug, string $url, array $attempts): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host)) {
            $this->preference->recordAttempts($sourceSlug, $host, $attempts);
        }
    }

    /** @return array<string, int> */
    public function preferenceValuesForSource(string $sourceSlug): array
    {
        $manifest = $this->manifests()->get($sourceSlug);
        if (! $manifest instanceof AdapterManifestDto) {
            return [];
        }

        $chain = SiteProfileDto::fromManifest($manifest)->fetchChain;
        $methods = [];
        foreach ($chain as $method) {
            if ($method === FetchMethod::Flaresolverr || $this->isPlaywright($method)) {
                $methods[$method->value] = 0;
            }
        }

        if ($methods === []) {
            return [];
        }

        $preferred = $this->preference->isFlaresolverrPreferredForSource($sourceSlug)
            ? FetchMethod::Flaresolverr
            : $this->firstBrowserMethod($chain);
        if ($preferred instanceof FetchMethod && array_key_exists($preferred->value, $methods)) {
            $methods[$preferred->value] = 1;
        }

        return $methods;
    }

    /** @param list<FetchMethod> $chain */
    private function hasBrowserMethods(array $chain): bool
    {
        $hasFlaresolverr = in_array(FetchMethod::Flaresolverr, $chain, true);
        $hasPlaywright = $this->firstPlaywright($chain) !== null;

        return $hasFlaresolverr && $hasPlaywright;
    }

    /** @param list<FetchMethod> $chain
     *  @return list<FetchMethod>
     */
    private function orderedChain(array $chain, bool $probePlaywright): array
    {
        $move = $probePlaywright ? $this->firstPlaywright($chain) : FetchMethod::Flaresolverr;
        $target = $probePlaywright ? FetchMethod::Flaresolverr : $this->firstPlaywright($chain);
        if (! $move instanceof FetchMethod || ! $target instanceof FetchMethod) {
            return $chain;
        }

        $from = array_search($move, $chain, true);
        $to = array_search($target, $chain, true);
        if (! is_int($from) || ! is_int($to) || $from < $to) {
            return $chain;
        }

        array_splice($chain, $from, 1);
        array_splice($chain, $to, 0, [$move]);

        return $chain;
    }

    /** @param list<FetchMethod> $chain */
    private function firstBrowserMethod(array $chain): ?FetchMethod
    {
        foreach ($chain as $method) {
            if ($method === FetchMethod::Flaresolverr || $this->isPlaywright($method)) {
                return $method;
            }
        }

        return null;
    }

    /** @param list<FetchMethod> $chain */
    private function firstPlaywright(array $chain): ?FetchMethod
    {
        foreach ($chain as $method) {
            if ($this->isPlaywright($method)) {
                return $method;
            }
        }

        return null;
    }

    private function isPlaywright(FetchMethod $method): bool
    {
        return in_array($method, [
            FetchMethod::Playwright,
            FetchMethod::PlaywrightStealth,
            FetchMethod::ChromeStealth,
        ], true);
    }

    private function manifests(): FileAdapterManifestRegistry
    {
        return self::$manifests ??= new FileAdapterManifestRegistry();
    }
}
