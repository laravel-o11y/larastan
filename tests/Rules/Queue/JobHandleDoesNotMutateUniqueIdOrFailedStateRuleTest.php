<?php

declare(strict_types=1);

namespace Tests\Rules\Queue;

use Larastan\Larastan\Collectors\QueuedJobMethodCallCollector;
use Larastan\Larastan\Collectors\QueuedJobPropertyUsageCollector;
use Larastan\Larastan\Rules\Queue\JobHandleDoesNotMutateUniqueIdOrFailedStateRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<JobHandleDoesNotMutateUniqueIdOrFailedStateRule> */
class JobHandleDoesNotMutateUniqueIdOrFailedStateRuleTest extends RuleTestCase
{
    private const UNIQUE_ID_TIP = "\n    💡 The lock is released using the key computed after handle(), so the lock acquired at dispatch is left held. Keep uniqueId() independent of state changed by handle().";

    private const FAILED_TIP = "\n    💡 failed() is called on a fresh copy of the job as it was dispatched and never sees changes made by handle().";

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(JobHandleDoesNotMutateUniqueIdOrFailedStateRule::class);
    }

    /** @inheritDoc */
    protected function getCollectors(): array
    {
        return [
            new QueuedJobMethodCallCollector(),
            new QueuedJobPropertyUsageCollector(),
        ];
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/data/job-handle-mutations.php', __DIR__ . '/data/job-handle-mutations-trait.php'], [
            [
                'Job Tests\Rules\Queue\Data\UniqueJobMutatingUniqueIdInput modifies $key in handle(), but the unique lock key depends on it.' . self::UNIQUE_ID_TIP,
                28,
            ],
            [
                'Job Tests\Rules\Queue\Data\UniqueJobMutatingUniqueIdProperty modifies $uniqueId in handle(), but the unique lock key depends on it.' . self::UNIQUE_ID_TIP,
                38,
            ],
            [
                'Job Tests\Rules\Queue\Data\UniqueJobWithUniqueIdHelper modifies $parts in handle(), but the unique lock key depends on it.' . self::UNIQUE_ID_TIP,
                92,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobFailedReadingHandleState modifies $temporaryPath in handle(), but failed() reads it.' . self::FAILED_TIP,
                107,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughHelpersAndClosures modifies $processedIds in handle(), but failed() reads it.' . self::FAILED_TIP,
                147,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughHelpersAndClosures modifies $attempt in handle(), but failed() reads it.' . self::FAILED_TIP,
                158,
            ],
            [
                'Job Tests\Rules\Queue\Data\ConcreteJobReadingInheritedHandleState modifies $temporaryPath in handle(), but failed() reads it.' . self::FAILED_TIP,
                229,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughReferences modifies $parts in handle(), but failed() reads it.' . self::FAILED_TIP,
                246,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughReferences modifies $current in handle(), but failed() reads it.' . self::FAILED_TIP,
                248,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughTraitHelper modifies $exportPath in handle(), but failed() reads it.' . self::FAILED_TIP,
                262,
            ],
            [
                'Job Tests\Rules\Queue\Data\BaseJobWithHelpers modifies $stage in handle(), but failed() reads it.' . self::FAILED_TIP,
                302,
            ],
            [
                'Job Tests\Rules\Queue\Data\BaseJobWithHelpers modifies $note in handle(), but failed() reads it.' . self::FAILED_TIP,
                307,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobOverridingHelpersOfBase modifies $stage in handle(), but failed() reads it.' . self::FAILED_TIP,
                311,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobOverridingHelpersOfBase modifies $note in handle(), but failed() reads it.' . self::FAILED_TIP,
                311,
            ],
            [
                'Job Tests\Rules\Queue\Data\JobMutatingThroughTraitFromOtherFile modifies $reportPath in handle(), but failed() reads it.' . self::FAILED_TIP,
                327,
            ],
            [
                'Job Tests\Rules\Queue\Data\QueuedJobExtendingNonQueuedBase modifies $stage in handle(), but failed() reads it.' . self::FAILED_TIP,
                397,
            ],
            [
                'Job Tests\Rules\Queue\Data\InvokableUniqueJobMutatingUniqueIdInput modifies $key in __invoke(), but the unique lock key depends on it.' . "\n    💡 The lock is released using the key computed after __invoke(), so the lock acquired at dispatch is left held. Keep uniqueId() independent of state changed by __invoke().",
                414,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
