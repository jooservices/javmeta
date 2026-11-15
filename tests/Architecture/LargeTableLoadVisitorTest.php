<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class LargeTableLoadVisitorTest extends TestCase
{
    public function test_select_raw_star_does_not_bound_a_large_table_load(): void
    {
        self::assertCount(1, $this->findings('Movie::query()->selectRaw(\'*\')->get();'));
    }

    public function test_grouping_by_a_large_table_identifier_does_not_bound_a_load(): void
    {
        self::assertCount(1, $this->findings("DB::table('movies')->groupBy('id')->get();"));
    }

    public function test_builder_assigned_to_a_variable_is_traced_to_the_terminal_load(): void
    {
        self::assertCount(1, $this->findings('$query = Movie::query();' . "\n" . '$query->get();'));
    }

    public function test_raw_select_builder_assigned_to_a_variable_is_still_unbounded(): void
    {
        self::assertCount(1, $this->findings("\$query = DB::table('movies')->selectRaw('*');\n\$query->get();"));
    }

    public function test_row_limit_bounds_a_direct_or_assigned_builder(): void
    {
        self::assertSame([], $this->findings("Movie::query()->selectRaw('*')->limit(10)->get();\n\$query = DB::table('movies')->limit(10);\n\$query->get();"));
    }

    public function test_scalar_aggregate_without_grouping_is_bounded(): void
    {
        self::assertSame([], $this->findings("DB::table('movies')->selectRaw('COUNT(*) AS total')->get();"));
    }

    public function test_limit_mutated_on_a_split_builder_bounds_the_read(): void
    {
        self::assertSame([], $this->findings("\$query = Movie::query();\n\$query->where('active', true);\n\$query->limit(10);\n\$query->get();"));
    }

    public function test_static_model_all_is_reported_as_an_unbounded_load(): void
    {
        self::assertCount(1, $this->findings('Movie::all();'));
    }

    /** @return list<string> */
    private function findings(string $source): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php $source");
        self::assertNotNull($ast);
        $visitor = new LargeTableLoadVisitor('fixture.php', ['movies'], ['Movie']);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->findings();
    }
}
