<?php

declare(strict_types=1);

namespace Larastan\Larastan\Collectors;

use Illuminate\Contracts\Queue\ShouldQueue;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassPropertiesNode;
use PHPStan\Node\Property\PropertyWrite;

use function strtolower;

/**
 * Collects the `$this` properties each method of a queued job, or of a class
 * a queued job may extend, reads and writes, keyed by lowercase method name.
 * Usages in closures belong to the enclosing method and usages in trait
 * methods to the using class. Each write records the file and line it occurs
 * on, which for a trait method is in the trait's file.
 *
 * @implements Collector<ClassPropertiesNode, array{string, array<string, array<string, true>>, array<string, array<string, array{string, int}>>}>
 */
final class QueuedJobPropertyUsageCollector implements Collector
{
    public function getNodeType(): string
    {
        return ClassPropertiesNode::class;
    }

    /**
     * @param ClassPropertiesNode $node
     *
     * @return array{string, array<string, array<string, true>>, array<string, array<string, array{string, int}>>}|null
     */
    public function processNode(Node $node, Scope $scope): array|null
    {
        $classReflection = $node->getClassReflection();

        // A base class need not implement ShouldQueue itself; a final one cannot be a base class.
        if ($classReflection->isAnonymous() || ($classReflection->isFinal() && ! $classReflection->is(ShouldQueue::class))) {
            return null;
        }

        $reads  = [];
        $writes = [];

        foreach ($node->getPropertyUsages() as $usage) {
            $fetch      = $usage->getFetch();
            $usageScope = $usage->getScope();
            $method     = $usageScope->getFunctionName();

            if (
                $method === null
                || ! $fetch instanceof PropertyFetch
                || ! $fetch->var instanceof Variable
                || $fetch->var->name !== 'this'
                || ! $fetch->name instanceof Identifier
            ) {
                continue;
            }

            $method   = strtolower($method);
            $property = $fetch->name->toString();

            if (! $usage instanceof PropertyWrite) {
                $reads[$method][$property] = true;

                continue;
            }

            if ($usage->isPromotedPropertyWrite()) {
                continue;
            }

            $file = $usageScope->isInTrait() ? $usageScope->getTraitReflection()->getFileName() : null;

            $writes[$method][$property] ??= [$file ?? $usageScope->getFile(), $fetch->getStartLine()];
        }

        return [$classReflection->getName(), $reads, $writes];
    }
}
