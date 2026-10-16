<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Console\Commands\Crawler\RunSourceCommand;
use App\Jobs\FetchListingJob;
use App\Models\CrawlQueue;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

final class RunSourceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_source_command_has_legacy_alias_and_preserves_signature_options(): void
    {
        $commands = app(Kernel::class)->all();
        $command = $commands['crawler:run-source'];
        $definition = $command->getDefinition();

        $this->assertArrayHasKey('crawl:source', $commands);
        $this->assertInstanceOf(RunSourceCommand::class, $command);
        $this->assertSame($command, $commands['crawl:source']);
        $this->assertFalse($definition->getArgument('slug')->isRequired());
        $this->assertSame('20', $definition->getOption('limit')->getDefault());
        $this->assertFalse($definition->getOption('dispatch')->acceptValue());
    }

    public function test_run_source_calls_split_commands_in_order_and_forwards_options_to_seed_and_feed_pool(): void
    {
        $calls = [];
        $application = new SymfonyApplication();
        $recordCommand = static fn(string $name, Closure $handler): SymfonyCommand => new class ($name, $handler) extends SymfonyCommand {
            /** @var Closure(InputInterface): int */
            private Closure $handler;

            /** @param Closure(InputInterface): int $handler */
            public function __construct(string $name, Closure $handler)
            {
                $this->handler = $handler;
                parent::__construct($name);

                if ($name === 'crawler:seed') {
                    $this->addOption('source', null, InputOption::VALUE_OPTIONAL);
                    $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, '', '50');
                }

                if ($name === 'crawler:feed-pool') {
                    $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, '', '50');
                }
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return ($this->handler)($input);
            }
        };

        $application->add($recordCommand('crawler:sync-sources', function (InputInterface $input) use (&$calls): int {
            $calls[] = ['command' => 'crawler:sync-sources'];

            return SymfonyCommand::SUCCESS;
        }));
        $application->add($recordCommand('crawler:reclaim', function (InputInterface $input) use (&$calls): int {
            $calls[] = ['command' => 'crawler:reclaim'];

            return SymfonyCommand::SUCCESS;
        }));
        $application->add($recordCommand('crawler:seed', function (InputInterface $input) use (&$calls): int {
            $calls[] = [
                'command' => 'crawler:seed',
                'source' => $input->getOption('source'),
                'limit' => $input->getOption('limit'),
            ];

            return SymfonyCommand::SUCCESS;
        }));
        $application->add($recordCommand('crawler:feed-pool', function (InputInterface $input) use (&$calls): int {
            $calls[] = ['command' => 'crawler:feed-pool', 'limit' => $input->getOption('limit')];

            return SymfonyCommand::SUCCESS;
        }));

        $sourceSlug = fake()->slug(2);
        $command = new RunSourceCommand();
        $command->setLaravel(app());
        $command->setApplication($application);
        $exitCode = $command->run(
            new ArrayInput(['slug' => $sourceSlug, '--dispatch' => true, '--limit' => '1'], $command->getDefinition()),
            new BufferedOutput(),
        );

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertSame([
            ['command' => 'crawler:sync-sources'],
            ['command' => 'crawler:reclaim'],
            ['command' => 'crawler:seed', 'source' => $sourceSlug, 'limit' => 1],
            ['command' => 'crawler:feed-pool', 'limit' => 1],
        ], $calls);
    }

    public function test_run_source_without_slug_seeds_every_enabled_source(): void
    {
        $sources = [
            fake()->unique()->slug(2) => [
                'name' => fake()->company(),
                'base_url' => fake()->url(),
                'priority' => 10,
                'movie_listing_urls' => [fake()->unique()->url()],
            ],
            fake()->unique()->slug(2) => [
                'name' => fake()->company(),
                'base_url' => fake()->url(),
                'priority' => 20,
                'movie_listing_urls' => [fake()->unique()->url()],
            ],
        ];
        config()->set('javmeta_sources.sources', $sources);
        $staleQueueRow = CrawlQueue::factory()->create([
            'source_slug' => fake()->unique()->slug(2),
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 0,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);

        $this->artisan('crawler:run-source')->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $staleQueueRow->refresh()->status);
        foreach ($sources as $slug => $source) {
            $this->assertDatabaseHas('sources', ['slug' => $slug]);
            $this->assertDatabaseHas('crawl_queue', [
                'source_slug' => $slug,
                'url' => $source['movie_listing_urls'][0],
                'status' => CrawlQueue::STATUS_PENDING,
            ]);
        }
    }

    public function test_run_source_dispatches_seeded_rows_when_dispatch_is_enabled(): void
    {
        $sourceSlug = fake()->unique()->slug(2);
        $seedUrls = [fake()->unique()->url(), fake()->unique()->url()];
        config()->set('javmeta_sources.sources', [
            $sourceSlug => [
                'name' => fake()->company(),
                'base_url' => fake()->url(),
                'priority' => 10,
                'gap_seconds_default' => 0,
                'gap_seconds_min' => 0,
                'gap_seconds_max' => 0,
                'movie_listing_urls' => $seedUrls,
            ],
        ]);
        Queue::fake();

        $this->artisan('crawler:run-source', ['slug' => $sourceSlug, '--dispatch' => true, '--limit' => 1])->assertSuccessful();

        Queue::assertPushed(FetchListingJob::class, 1);
        $this->assertSame(
            CrawlQueue::STATUS_CLAIMED,
            CrawlQueue::query()->where('source_slug', $sourceSlug)->where('url', $seedUrls[0])->firstOrFail()->status,
        );
        $this->assertDatabaseMissing('crawl_queue', ['source_slug' => $sourceSlug, 'url' => $seedUrls[1]]);
    }
}
