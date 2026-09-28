<?php

declare(strict_types=1);

namespace Larastan\Larastan\Rules\Queue;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Larastan\Larastan\Collectors\QueuedJobMethodCallCollector;
use Larastan\Larastan\Collectors\QueuedJobPropertyUsageCollector;
use Larastan\Larastan\Concerns\InspectsQueuedJobs;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function count;
use function sprintf;

/**
 * A queued job's `handle()` must not modify properties that `uniqueId()` or
 * `failed()` depend on.
 *
 * `failed()` is called on a fresh instance unserialized from the queued
 * payload, so it only sees the state the job had when it was dispatched; any
 * value `handle()` stored on the job is gone.
 *
 * For a `ShouldBeUnique` job, `Illuminate\Bus\UniqueLock::getKey()` evaluates
 * `uniqueId()` (or the `$uniqueId` property) once at dispatch to acquire the
 * lock and again on the same instance after `handle()` to release it. If
 * `handle()` changed its inputs, the release targets a different key and the
 * dispatch lock stays held until `uniqueFor` expires, silently dropping every
 * later dispatch. `ShouldBeUniqueUntilProcessing` jobs release the lock before
 * `handle()` runs and are not affected.
 *
 * Property reads and writes come from PHPStan's own tracking. Methods called
 * on `$this`, `static`, `self` and `parent` are followed across the collected
 * classes of the hierarchy, so helpers in parent classes and traits count.
 *
 * The entry point is `handle()`, or `__invoke()` for invokable jobs.
 *
 * @phpstan-type Hierarchy array<string, array{parent: string|null, file: string, line: int, methods: array<string, bool>, calls: array<string, list<array{string, string}>>}>
 * @phpstan-type Usages array<string, array{array<string, array<string, true>>, array<string, array<string, array{string, int}>>}>
 * @implements Rule<CollectedDataNode>
 */
class JobHandleDoesNotMutateUniqueIdOrFailedStateRule implements Rule
{
    use InspectsQueuedJobs;

    private const READS = 0;

    private const WRITES = 1;

    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $hierarchy = [];

        foreach ($node->get(QueuedJobMethodCallCollector::class) as $rows) {
            foreach ($rows as [$class, $parent, $file, $line, $methods, $calls]) {
                $hierarchy[$class] = ['parent' => $parent, 'file' => $file, 'line' => $line, 'methods' => $methods, 'calls' => $calls];
            }
        }

        $usages = [];

        foreach ($node->get(QueuedJobPropertyUsageCollector::class) as $rows) {
            foreach ($rows as [$class, $reads, $writes]) {
                $usages[$class] = [$reads, $writes];
            }
        }

        $errors = [];

        foreach ($hierarchy as $job => $declaration) {
            if (! $this->reflectionProvider->hasClass($job)) {
                continue;
            }

            $classReflection = $this->reflectionProvider->getClass($job);

            if (! $classReflection->is(ShouldQueue::class) || ! $this->isDispatchableClass($classReflection)) {
                continue;
            }

            // Illuminate\Bus\Dispatcher::dispatchNow() falls back to __invoke() when there is no handle().
            $entryName = $classReflection->hasNativeMethod('handle') ? 'handle' : '__invoke';
            $entry     = $this->resolve($hierarchy, $job, $entryName);

            if ($entry === null) {
                continue;
            }

            $uniqueIdReads = [];

            if ($classReflection->is(ShouldBeUnique::class) && ! $classReflection->is(ShouldBeUniqueUntilProcessing::class)) {
                if ($classReflection->hasNativeMethod('uniqueId')) {
                    $uniqueIdReads = $this->reads($usages, $this->reachableMethods($hierarchy, $job, $this->resolve($hierarchy, $job, 'uniqueid')));
                } elseif ($classReflection->hasNativeProperty('uniqueId')) {
                    $uniqueIdReads = ['uniqueId' => true];
                }
            }

            $failedReads = $this->reads($usages, $this->reachableMethods($hierarchy, $job, $this->resolve($hierarchy, $job, 'failed')));

            if ($uniqueIdReads === [] && $failedReads === []) {
                continue;
            }

            foreach ($this->writes($usages, $this->reachableMethods($hierarchy, $job, $entry)) as $property => [$writingClass, $file, $line]) {
                // Writes in a parent class or a trait from another file are reported on the job.
                $line = $writingClass === $job && $file === $declaration['file'] ? $line : $declaration['line'];

                if (isset($uniqueIdReads[$property])) {
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        'Job %s modifies $%s in %s(), but the unique lock key depends on it.',
                        $classReflection->getDisplayName(),
                        $property,
                        $entryName,
                    ))
                        ->tip(sprintf('The lock is released using the key computed after %s(), so the lock acquired at dispatch is left held. Keep uniqueId() independent of state changed by %1$s().', $entryName))
                        ->identifier('larastan.jobHandleMutatesUniqueId')
                        ->file($declaration['file'])
                        ->line($line)
                        ->build();
                }

                if (! isset($failedReads[$property])) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Job %s modifies $%s in %s(), but failed() reads it.',
                    $classReflection->getDisplayName(),
                    $property,
                    $entryName,
                ))
                    ->tip(sprintf('failed() is called on a fresh copy of the job as it was dispatched and never sees changes made by %s().', $entryName))
                    ->identifier('larastan.jobHandleMutatesFailedState')
                    ->file($declaration['file'])
                    ->line($line)
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * Finds the class in the collected hierarchy that declares the method.
     *
     * @param Hierarchy $hierarchy
     *
     * @return array{string, string}|null
     */
    private function resolve(array $hierarchy, string|null $class, string $method): array|null
    {
        while ($class !== null && isset($hierarchy[$class])) {
            if (isset($hierarchy[$class]['methods'][$method])) {
                return [$class, $method];
            }

            $class = $hierarchy[$class]['parent'];
        }

        return null;
    }

    /**
     * Lists the entry method and every method it calls, transitively, as the
     * class declaring it and its lowercase name, in breadth-first order.
     *
     * @param Hierarchy                  $hierarchy
     * @param array{string, string}|null $entry
     *
     * @return list<array{string, string}>
     */
    private function reachableMethods(array $hierarchy, string $job, array|null $entry): array
    {
        if ($entry === null) {
            return [];
        }

        $queue   = [$entry];
        $visited = [$entry[0] => [$entry[1] => true]];

        for ($i = 0; $i < count($queue); $i++) {
            [$class, $method] = $queue[$i];

            foreach ($hierarchy[$class]['calls'][$method] ?? [] as [$target, $callee]) {
                $resolved = match ($target) {
                    'parent' => $this->resolve($hierarchy, $hierarchy[$class]['parent'], $callee),
                    'self' => $this->resolve($hierarchy, $class, $callee),
                    // A private method is not overridable, so `$this` calls it on the declaring class.
                    default => $hierarchy[$class]['methods'][$callee] ?? false
                        ? [$class, $callee]
                        : $this->resolve($hierarchy, $job, $callee),
                };

                if ($resolved === null || isset($visited[$resolved[0]][$resolved[1]])) {
                    continue;
                }

                $visited[$resolved[0]][$resolved[1]] = true;
                $queue[]                             = $resolved;
            }
        }

        return $queue;
    }

    /**
     * @param Usages                      $usages
     * @param list<array{string, string}> $methods
     *
     * @return array<string, true>
     */
    private function reads(array $usages, array $methods): array
    {
        $result = [];

        foreach ($methods as [$class, $method]) {
            $result += $usages[$class][self::READS][$method] ?? [];
        }

        return $result;
    }

    /**
     * Maps each property the methods write to the class whose method wrote it
     * first and the file and line of that write.
     *
     * @param Usages                      $usages
     * @param list<array{string, string}> $methods
     *
     * @return array<string, array{string, string, int}>
     */
    private function writes(array $usages, array $methods): array
    {
        $result = [];

        foreach ($methods as [$class, $method]) {
            foreach ($usages[$class][self::WRITES][$method] ?? [] as $property => [$file, $line]) {
                $result[$property] ??= [$class, $file, $line];
            }
        }

        return $result;
    }
}
