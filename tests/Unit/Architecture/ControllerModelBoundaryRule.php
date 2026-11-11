<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\MultipleNodeTypesRule;
use PHPStan\Rules\RuleErrorBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;

/** @implements MultipleNodeTypesRule<Node> */
final class ControllerModelBoundaryRule implements MultipleNodeTypesRule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return non-empty-list<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            Use_::class,
            GroupUse::class,
            StaticCall::class,
            StaticPropertyFetch::class,
            ClassConstFetch::class,
            Instanceof_::class,
            New_::class,
            Param::class,
            Property::class,
            ClassMethod::class,
            Function_::class,
            Closure::class,
            ArrowFunction::class,
            MethodCall::class,
            NullsafeMethodCall::class,
            PropertyFetch::class,
            NullsafePropertyFetch::class,
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $controller = ControllerModelBoundaryBaseline::controllerName($scope);
        if ($controller === null) {
            return [];
        }

        if ($node instanceof Use_) {
            if ($node->type !== Use_::TYPE_NORMAL) {
                return [];
            }

            $errors = [];
            foreach ($node->uses as $use) {
                if ($use->type !== Use_::TYPE_UNKNOWN && $use->type !== Use_::TYPE_NORMAL) {
                    continue;
                }

                $errors = array_merge(
                    $errors,
                    $this->errorsForModel($scope, $controller, $use->name->toString(), $node->getStartLine()),
                );
            }

            return $errors;
        }

        if ($node instanceof GroupUse) {
            $errors = [];
            foreach ($node->uses as $use) {
                $useType = $use->type === Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                if ($useType !== Use_::TYPE_NORMAL) {
                    continue;
                }

                $modelName = rtrim($node->prefix->toString(), '\\') . '\\' . ltrim($use->name->toString(), '\\');
                $errors = array_merge(
                    $errors,
                    $this->errorsForModel($scope, $controller, $modelName, $node->getStartLine()),
                );
            }

            return $errors;
        }

        if ($node instanceof StaticCall
            || $node instanceof StaticPropertyFetch
            || $node instanceof ClassConstFetch
            || $node instanceof Instanceof_
            || $node instanceof New_) {
            $class = $node->class;
            if ($class instanceof Name) {
                return $this->errorsForModel($scope, $controller, $scope->resolveName($class), $node->getStartLine());
            }

            if ($class instanceof Expr) {
                return $this->errorsForTypes($scope, $controller, $scope->getType($class)->getObjectClassNames(), $node->getStartLine());
            }
        }

        $declaredType = match (true) {
            $node instanceof Param, $node instanceof Property => $node->type,
            $node instanceof ClassMethod,
            $node instanceof Function_,
            $node instanceof Closure,
            $node instanceof ArrowFunction => $node->returnType,
            default => null,
        };
        if ($declaredType !== null) {
            $errors = [];
            foreach ($this->namesInType($declaredType) as $name) {
                $errors = array_merge(
                    $errors,
                    $this->errorsForModel($scope, $controller, $scope->resolveName($name), $name->getStartLine()),
                );
            }

            return $errors;
        }

        if ($node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof PropertyFetch
            || $node instanceof NullsafePropertyFetch) {
            return $this->errorsForTypes(
                $scope,
                $controller,
                $scope->getType($node->var)->getObjectClassNames(),
                $node->getStartLine(),
            );
        }

        return [];
    }

    /**
     * @param class-string $controller
     * @return list<IdentifierRuleError>
     */
    private function errorsForModel(Scope $scope, string $controller, string $model, int $line): array
    {
        if (! ControllerModelBoundaryBaseline::isModel($model)
            || ControllerModelBoundaryBaseline::allows($scope, $controller, $model, $line)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Controller %s must not depend directly on model %s; use a service or repository instead.',
                $controller,
                $model,
            ))
                ->identifier('architecture.controllerModelBoundary')
                ->build(),
        ];
    }

    /**
     * @param class-string $controller
     * @param list<class-string> $models
     * @return list<IdentifierRuleError>
     */
    private function errorsForTypes(Scope $scope, string $controller, array $models, int $line): array
    {
        $errors = [];
        foreach ($models as $model) {
            $errors = array_merge($errors, $this->errorsForModel($scope, $controller, $model, $line));
        }

        return $errors;
    }

    /**
     * @return list<Name>
     */
    private function namesInType(Node $type): array
    {
        if ($type instanceof Name) {
            return [$type];
        }

        $names = [];
        foreach ($type->getSubNodeNames() as $subNodeName) {
            $value = $type->{$subNodeName};
            if ($value instanceof Node) {
                $names = array_merge($names, $this->namesInType($value));
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $names = array_merge($names, $this->namesInType($item));
                    }
                }
            }
        }

        return $names;
    }
}
