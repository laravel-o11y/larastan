<?php

declare(strict_types=1);

namespace Tests\Rules\Queue;

use Larastan\Larastan\Rules\Queue\JobWithUniqueIdIsUniqueRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<JobWithUniqueIdIsUniqueRule> */
class JobWithUniqueIdIsUniqueRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(JobWithUniqueIdIsUniqueRule::class);
    }

    public function testRule(): void
    {
        $tip = "\n    💡 Implement Illuminate\\Contracts\\Queue\\ShouldBeUnique or ShouldBeUniqueUntilProcessing, otherwise uniqueId is ignored.";

        $this->analyse([__DIR__ . '/data/unique-id-jobs.php'], [
            [
                'Job Tests\Rules\Queue\Data\QueuedJobWithUniqueIdMethod declares uniqueId but does not implement ShouldBeUnique.' . $tip,
                11,
            ],
            [
                'Job Tests\Rules\Queue\Data\QueuedJobWithUniqueIdProperty declares uniqueId but does not implement ShouldBeUnique.' . $tip,
                23,
            ],
            [
                'Job Tests\Rules\Queue\Data\QueuedJobInheritingUniqueId declares uniqueId but does not implement ShouldBeUnique.' . $tip,
                51,
            ],
            [
                'Job Tests\Rules\Queue\Data\QueuedJobWithUniqueIdFromTrait declares uniqueId but does not implement ShouldBeUnique.' . $tip,
                67,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
