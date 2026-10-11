<?php

declare(strict_types=1);

namespace Tests\Rules\Queue\Data;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedJobWithUniqueIdMethod implements ShouldQueue
{
    public function __construct(public int $companyId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }
}

class QueuedJobWithUniqueIdProperty implements ShouldQueue
{
    public string $uniqueId = 'fixed';
}

class UniqueQueuedJobWithUniqueId implements ShouldQueue, ShouldBeUnique
{
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'fixed';
    }
}

class UniqueUntilProcessingQueuedJobWithUniqueId implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    public string $uniqueId = 'fixed';
}

abstract class AbstractQueuedJobWithUniqueId implements ShouldQueue
{
    public function uniqueId(): string
    {
        return 'fixed';
    }
}

class QueuedJobInheritingUniqueId extends AbstractQueuedJobWithUniqueId
{
}

class UniqueQueuedJobInheritingUniqueId extends AbstractQueuedJobWithUniqueId implements ShouldBeUnique
{
}

trait ProvidesUniqueId
{
    public function uniqueId(): string
    {
        return 'fixed';
    }
}

class QueuedJobWithUniqueIdFromTrait implements ShouldQueue
{
    use ProvidesUniqueId;
}

class QueuedJobWithoutUniqueId implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class NonQueuedClassWithUniqueId
{
    public function uniqueId(): string
    {
        return 'fixed';
    }
}
