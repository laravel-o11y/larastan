<?php

declare(strict_types=1);

namespace Larastan\Larastan\Collectors;

use Illuminate\Contracts\Queue\ShouldQueue;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassMethodsNode;

use function strtolower;

/**
 * Collects the methods of a queued job, or of a class a queued job may
 * extend, and the calls each of them makes on `$this`, `static`, `self` and
 * `parent`, so that a rule can follow helper methods across the class
 * hierarchy. Method names are lowercase; each method maps to
 * whether it is private. Calls through `static` are recorded as `this`.
 *
 * @implements Collector<ClassMethodsNode, array{string, string|null, string, int, array<string, bool>, array<string, list<array{string, string}>>}>
 */
final class QueuedJobMethodCallCollector implements Collector
{
    public function getNodeType(): string
    {
        return ClassMethodsNode::class;
    }

    /**
     * @param ClassMethodsNode $node
     *
     * @return array{string, string|null, string, int, array<string, bool>, array<string, list<array{string, string}>>}|null
     */
    public function processNode(Node $node, Scope $scope): array|null
    {
        $classReflection = $node->getClassReflection();

        // A base class need not implement ShouldQueue itself; a final one cannot be a base class.
        if ($classReflection->isAnonymous() || ($classReflection->isFinal() && ! $classReflection->is(ShouldQueue::class))) {
            return null;
        }

        $methods = [];

        foreach ($node->getMethods() as $method) {
            $methods[$method->getNode()->name->toLowerString()] = $method->getNode()->isPrivate();
        }

        $calls = [];

        foreach ($node->getMethodCalls() as $methodCall) {
            $call   = $methodCall->getNode();
            $caller = $methodCall->getScope()->getFunctionName();

            if ($caller === null || ! ($call instanceof MethodCall || $call instanceof StaticCall) || ! $call->name instanceof Identifier) {
                continue;
            }

            if ($call instanceof MethodCall) {
                if (! $call->var instanceof Variable || $call->var->name !== 'this') {
                    continue;
                }

                $target = 'this';
            } else {
                if (! $call->class instanceof Name) {
                    continue;
                }

                $target = match ($call->class->toLowerString()) {
                    'static' => 'this',
                    'self' => 'self',
                    'parent' => 'parent',
                    default => null,
                };

                if ($target === null) {
                    continue;
                }
            }

            $calls[strtolower($caller)][] = [$target, $call->name->toLowerString()];
        }

        return [
            $classReflection->getName(),
            $classReflection->getParentClass()?->getName(),
            $scope->getFile(),
            $node->getClass()->getStartLine(),
            $methods,
            $calls,
        ];
    }
}
