<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Console\Commands\CrawlTickCommand;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

final class CrawlTickCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_tick_delegates_in_order_and_forwards_only_seed_options(): void
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
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return ($this->handler)($input);
            }
        };

        $application->addCommand($recordCommand('crawler:sync-sources', function (InputInterface $input) use (&$calls): int {
            $calls[] = ['command' => 'crawler:sync-sources'];

            return SymfonyCommand::SUCCESS;
        }));
        $application->addCommand($recordCommand('crawler:reclaim', function (InputInterface $input) use (&$calls): int {
            $calls[] = ['command' => 'crawler:reclaim'];

            return SymfonyCommand::SUCCESS;
        }));
        $application->addCommand($recordCommand('crawler:seed', function (InputInterface $input) use (&$calls): int {
            $calls[] = [
                'command' => 'crawler:seed',
                'source' => $input->getOption('source'),
                'limit' => $input->getOption('limit'),
            ];

            return SymfonyCommand::SUCCESS;
        }));

        $sourceSlug = fake()->slug(2);
        $command = new CrawlTickCommand();
        $command->setLaravel(app());
        $command->setApplication($application);
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput(['--source' => $sourceSlug, '--limit' => '1'], $command->getDefinition()), $output);

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('deprecated', $output->fetch());
        $this->assertSame([
            ['command' => 'crawler:sync-sources'],
            ['command' => 'crawler:reclaim'],
            ['command' => 'crawler:seed', 'source' => $sourceSlug, 'limit' => '1'],
        ], $calls);
    }

    public function test_tick_with_real_commands_preserves_the_combined_database_effects(): void
    {
        $sourceSlug = fake()->unique()->slug(2);
        $otherSourceSlug = fake()->unique()->slug(2);
        $seedUrls = [fake()->unique()->url(), fake()->unique()->url()];
        $otherSeedUrl = fake()->unique()->url();

        config()->set('javmeta_sources.sources', [
            $sourceSlug => [
                'name' => fake()->company(),
                'base_url' => fake()->url(),
                'priority' => 10,
                'movie_listing_urls' => $seedUrls,
            ],
            $otherSourceSlug => [
                'name' => fake()->company(),
                'base_url' => fake()->url(),
                'priority' => 20,
                'movie_listing_urls' => [$otherSeedUrl],
            ],
        ]);

        $staleQueueRow = CrawlQueue::factory()->create([
            'source_slug' => $otherSourceSlug,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 0,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);

        $registeredNames = array_keys(array_filter(
            app(Kernel::class)->all(),
            static fn(SymfonyCommand $command): bool => $command instanceof CrawlTickCommand,
        ));
        $this->assertCount(1, $registeredNames);

        $this->artisan($registeredNames[0], ['--source' => $sourceSlug, '--limit' => 1])
            ->expectsOutputToContain('deprecated')
            ->assertSuccessful();

        $this->assertSame(2, Source::query()->count());
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', $sourceSlug)->count());
        $this->assertSame(1, CrawlRun::query()->where('source_slug', $sourceSlug)->count());
        $this->assertSame(CrawlQueue::STATUS_PENDING, $staleQueueRow->refresh()->status);
        $this->assertDatabaseMissing('crawl_queue', ['source_slug' => $otherSourceSlug, 'url' => $otherSeedUrl]);
    }
}
