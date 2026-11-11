<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

/** @internal */
final class LargeTableLoadVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    private array $found = [];

    /** @var array<string, Expr> */
    private array $queryVariables = [];

    /** @var list<array<string, Expr>> */
    private array $scopeStack = [];

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
        if ($node instanceof FunctionLike) {
            $this->scopeStack[] = $this->queryVariables;
            if (! $node instanceof Expr\Closure && ! $node instanceof Expr\ArrowFunction) {
                $this->queryVariables = [];
            }
        }

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            if ($this->hasTerminalLoad($node->expr)) {
                unset($this->queryVariables[$node->var->name]);
            } elseif ($this->rootVariable($node->expr) !== $node->var->name) {
                $this->queryVariables[$node->var->name] = $node->expr;
            }
        }

        if ($node instanceof MethodCall && $node->name instanceof Identifier
            && $node->var instanceof Variable && is_string($node->var->name)
            && isset($this->queryVariables[$node->var->name])
            && ! in_array($node->name->toString(), ['get', 'all', 'pluck'], true)) {
            $this->queryVariables[$node->var->name] = new MethodCall(
                $this->queryVariables[$node->var->name],
                $node->name,
                $node->args,
            );
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && $node->name instanceof Identifier
            && $node->name->toString() === 'all' && in_array($this->shortName($node->class), $this->models, true)) {
            $this->found[] = $this->path . ':' . $node->getStartLine();

            return null;
        }

        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier
            || ! in_array($node->name->toString(), ['get', 'all', 'pluck'], true)) {
            return null;
        }

        $methods = [];
        $calls = [];
        $tableFound = $this->traceQuery($node->var, $methods, $calls, []);
        if (! $tableFound || array_intersect($methods, [
            'limit', 'take', 'forPage', 'paginate', 'simplePaginate', 'chunk', 'chunkById', 'lazyById', 'cursor',
            'count', 'exists', 'value', 'sum', 'avg', 'min', 'max',
        ]) !== [] || $this->hasScalarAggregate($calls, $methods)) {
            return null;
        }

        $this->found[] = $this->path . ':' . $node->getStartLine();

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof FunctionLike) {
            $this->queryVariables = array_pop($this->scopeStack) ?? [];
        }

        return null;
    }

    /** @return list<string> */
    public function findings(): array
    {
        return $this->found;
    }

    /**
     * @param list<string> $methods
     * @param list<MethodCall|StaticCall> $calls
     * @param list<string> $visitedVariables
     */
    private function traceQuery(Expr $expression, array &$methods, array &$calls, array $visitedVariables): bool
    {
        if ($expression instanceof MethodCall && $expression->name instanceof Identifier) {
            $calls[] = $expression;
            $method = $expression->name->toString();
            $methods[] = $method;

            if ($method === 'modelQuery' && isset($expression->args[0]->value)
                && $expression->args[0]->value instanceof Expr\ClassConstFetch
                && $expression->args[0]->value->class instanceof Name
                && $expression->args[0]->value->name instanceof Identifier
                && $expression->args[0]->value->name->toString() === 'class'
                && in_array($this->shortName($expression->args[0]->value->class), $this->models, true)) {
                return true;
            }

            return $this->traceQuery($expression->var, $methods, $calls, $visitedVariables);
        }

        if ($expression instanceof StaticCall && $expression->class instanceof Name && $expression->name instanceof Identifier) {
            $method = $expression->name->toString();
            $calls[] = $expression;
            $methods[] = $method;
            if (strcasecmp($this->shortName($expression->class), 'DB') === 0 && $method === 'table'
                && isset($expression->args[0]->value) && $expression->args[0]->value instanceof String_
                && in_array($expression->args[0]->value->value, $this->tables, true)) {
                return true;
            }

            return in_array($this->shortName($expression->class), $this->models, true)
                && in_array($method, ['query', 'newQuery', 'where', 'whereIn', 'whereNull', 'whereNotNull', 'orderBy', 'select'], true);
        }

        if ($expression instanceof Variable && is_string($expression->name)
            && ! in_array($expression->name, $visitedVariables, true)
            && isset($this->queryVariables[$expression->name])) {
            $visitedVariables[] = $expression->name;

            return $this->traceQuery($this->queryVariables[$expression->name], $methods, $calls, $visitedVariables);
        }

        return false;
    }

    /**
     * @param list<MethodCall|StaticCall> $calls
     * @param list<string> $methods
     */
    private function hasScalarAggregate(array $calls, array $methods): bool
    {
        if (in_array('groupBy', $methods, true) || in_array('groupByRaw', $methods, true)) {
            return false;
        }

        foreach ($calls as $call) {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier || $call->name->toString() !== 'selectRaw'
                || ! isset($call->args[0]->value) || ! $call->args[0]->value instanceof String_) {
                continue;
            }

            return preg_match('/^\s*(?:COUNT|SUM|AVG|MIN|MAX)\s*\([\s\S]+\)\s*(?:AS\s+[A-Za-z_][A-Za-z0-9_]*)?\s*$/i', $call->args[0]->value->value) === 1;
        }

        return false;
    }

    private function hasTerminalLoad(Expr $expression): bool
    {
        while ($expression instanceof MethodCall && $expression->name instanceof Identifier) {
            if (in_array($expression->name->toString(), ['get', 'all', 'pluck'], true)) {
                return true;
            }
            $expression = $expression->var;
        }

        return false;
    }

    private function rootVariable(Expr $expression): ?string
    {
        while ($expression instanceof MethodCall) {
            $expression = $expression->var;
        }

        return $expression instanceof Variable && is_string($expression->name)
            ? $expression->name
            : null;
    }

    private function shortName(Name $name): string
    {
        $parts = $name->getParts();

        return (string) end($parts);
    }
}
