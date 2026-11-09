<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class LargeTableLoadVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $found = [];

    /** @param list<string> $tables
     *  @param list<string> $models
     */
    public function __construct(
        private readonly string $path,
        private readonly array $tables,
        private readonly array $models,
    ) {}

    public function enterNode(Node $node): ?int
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier
            || ! in_array($node->name->toString(), ['get', 'all', 'pluck'], true)) {
            return null;
        }

        $methods = [];
        $cursor = $node->var;
        $tableFound = false;
        while ($cursor instanceof MethodCall && $cursor->name instanceof Identifier) {
            $methods[] = $cursor->name->toString();
            if ($cursor->name->toString() === 'modelQuery' && isset($cursor->args[0]->value)
                && $cursor->args[0]->value instanceof Expr\ClassConstFetch
                && $cursor->args[0]->value->class instanceof Name
                && in_array($cursor->args[0]->value->class->toString(), $this->models, true)) {
                $tableFound = true;
            }
            $cursor = $cursor->var;
        }

        if ($cursor instanceof StaticCall && $cursor->class instanceof Name && $cursor->name instanceof Identifier) {
            $class = $cursor->class->toString();
            $method = $cursor->name->toString();
            if (strcasecmp($class, 'DB') === 0 && $method === 'table'
                && isset($cursor->args[0]->value) && $cursor->args[0]->value instanceof String_
                && in_array($cursor->args[0]->value->value, $this->tables, true)) {
                $tableFound = true;
            }
            if (in_array($class, $this->models, true)
                && in_array($method, ['query', 'newQuery', 'where', 'whereIn', 'whereNull', 'whereNotNull', 'orderBy', 'select'], true)) {
                $tableFound = true;
            }
            $methods[] = $method;
        }

        if (! $tableFound || array_intersect($methods, [
            'limit', 'take', 'paginate', 'simplePaginate', 'chunk', 'chunkById', 'lazyById', 'cursor',
            'count', 'exists', 'value', 'selectRaw', 'groupBy', 'sum', 'avg', 'min', 'max',
        ]) !== []) {
            return null;
        }

        $this->found[] = $this->path . ':' . $node->getStartLine();

        return null;
    }

    /** @return list<string> */
    public function findings(): array
    {
        return $this->found;
    }
}
