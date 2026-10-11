<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Queue;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Larastan\Larastan\Concerns\InspectsQueuedJobs;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * A queued job (or queued listener) declaring `uniqueId`, either as a method
 * (`public function uniqueId(): string`) or a property (`public $uniqueId`),
 * must implement `ShouldBeUnique` or `ShouldBeUniqueUntilProcessing`.
 *
 * Laravel only acquires a uniqueness lock for a job that is an instance of
 * `ShouldBeUnique` (see `Illuminate\Foundation\Bus\PendingDispatch::shouldDispatch()`
 * and `Illuminate\Events\Dispatcher::propagateListenerOptions()`). Without the
 * interface `uniqueId` is never read, so every dispatch is queued and the
 * intended deduplication silently does nothing.
 *
 * @implements Rule<InClassNode>
 */
class JobWithUniqueIdIsUniqueRule implements Rule
{
    use InspectsQueuedJobs;

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $this->isDispatchableClass($classReflection)) {
            return [];
        }

        if (! $classReflection->is(ShouldQueue::class) || $classReflection->is(ShouldBeUnique::class)) {
            return [];
        }

        if (! $classReflection->hasNativeMethod('uniqueId') && ! $classReflection->hasNativeProperty('uniqueId')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Job %s declares uniqueId but does not implement ShouldBeUnique.',
                $classReflection->getDisplayName(),
            ))
                ->tip('Implement Illuminate\\Contracts\\Queue\\ShouldBeUnique or ShouldBeUniqueUntilProcessing, otherwise uniqueId is ignored.')
                ->identifier('larastan.jobUniqueIdIsUnique')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
