<?php

declare(strict_types=1);

namespace Tests\Rules\Queue\Data;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

use function array_map;

class UniqueJobMutatingUniqueIdInput implements ShouldQueue, ShouldBeUnique
{
    public function __construct(private string $key)
    {
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        $this->key = 'processed';
    }
}

class UniqueJobMutatingUniqueIdProperty implements ShouldQueue, ShouldBeUnique
{
    public string $uniqueId = 'initial';

    public function handle(): void
    {
        $this->uniqueId .= '-processed';
    }
}

class UniqueJobMutatingOtherState implements ShouldQueue, ShouldBeUnique
{
    private int $processed = 0;

    public function __construct(private string $key)
    {
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        $this->processed++;
    }
}

class UniqueUntilProcessingJobMutatingUniqueIdInput implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    public function __construct(private string $key)
    {
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        $this->key = 'processed';
    }
}

class UniqueJobWithUniqueIdHelper implements ShouldQueue, ShouldBeUnique
{
    /** @param list<string> $parts */
    public function __construct(private array $parts)
    {
    }

    public function uniqueId(): string
    {
        return $this->joinedParts();
    }

    public function handle(): void
    {
        $this->parts[] = 'processed';
    }

    private function joinedParts(): string
    {
        return implode(':', $this->parts);
    }
}

class JobFailedReadingHandleState implements ShouldQueue
{
    private ?string $temporaryPath = null;

    public function handle(): void
    {
        $this->temporaryPath = '/tmp/export';
    }

    public function failed(Throwable $e): void
    {
        if ($this->temporaryPath !== null) {
            unlink($this->temporaryPath);
        }
    }
}

class JobFailedReadingConstructorState implements ShouldQueue
{
    public function __construct(private string $path)
    {
    }

    public function handle(): void
    {
        file_put_contents($this->path, 'data');
    }

    public function failed(Throwable $e): void
    {
        unlink($this->path);
    }
}

class JobMutatingThroughHelpersAndClosures implements ShouldQueue
{
    private int $attempt = 0;

    /** @var list<int> */
    private array $processedIds = [];

    public function handle(): void
    {
        $this->prepare();

        DB::transaction(function (): void {
            $this->processedIds = array_map(static fn (int $id): int => $id, [1, 2]);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->report();
    }

    private function prepare(): void
    {
        ++$this->attempt;
    }

    private function report(): void
    {
        logger()->error('Failed', ['attempt' => $this->attempt, 'ids' => $this->processedIds]);
    }
}

class JobWithAnonymousClassInHandle implements ShouldQueue
{
    private string $status = 'pending';

    public function handle(): void
    {
        new class {
            private string $status = 'pending';

            public function run(): void
            {
                $this->status = 'done';
            }
        };
    }

    public function failed(Throwable $e): void
    {
        logger()->error($this->status);
    }
}

class JobFailedOnlyWritingHandleState implements ShouldQueue
{
    private bool $cleaned = false;

    public function handle(): void
    {
        $this->cleaned = false;
    }

    public function failed(Throwable $e): void
    {
        $this->cleaned = true;
    }
}

class NonQueuedJobMutatingFailedState
{
    private ?string $temporaryPath = null;

    public function handle(): void
    {
        $this->temporaryPath = '/tmp/export';
    }

    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->temporaryPath);
    }
}

abstract class AbstractJobMutatingFailedState implements ShouldQueue
{
    protected ?string $temporaryPath = null;

    public function handle(): void
    {
        $this->temporaryPath = '/tmp/export';
    }
}

class ConcreteJobReadingInheritedHandleState extends AbstractJobMutatingFailedState
{
    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->temporaryPath);
    }
}

class JobMutatingThroughReferences implements ShouldQueue
{
    /** @var list<string> */
    private array $parts = [];

    private string $current = '';

    public function handle(): void
    {
        array_push($this->parts, 'processed');

        foreach (['a', 'b'] as $this->current) {
        }
    }

    public function failed(Throwable $e): void
    {
        logger()->error($this->current, $this->parts);
    }
}

trait WritesExportPath
{
    private function rememberExportPath(): void
    {
        $this->exportPath = '/tmp/export';
    }
}

class JobMutatingThroughTraitHelper implements ShouldQueue
{
    use WritesExportPath;

    private ?string $exportPath = null;

    public function handle(): void
    {
        $this->rememberExportPath();
    }

    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->exportPath);
    }
}

class BaseJobWithHelpers implements ShouldQueue
{
    protected string $stage = 'queued';

    protected string $note = '';

    public function handle(): void
    {
        $this->markStarted();
        self::describe();
    }

    public function failed(Throwable $e): void
    {
        logger()->error($this->stage . $this->note);
    }

    private function markStarted(): void
    {
        $this->stage = 'started';
    }

    protected function describe(): void
    {
        $this->note = 'base';
    }
}

class JobOverridingHelpersOfBase extends BaseJobWithHelpers
{
    public function handle(): void
    {
        parent::handle();
    }

    protected function describe(): void
    {
    }

    private function markStarted(): void
    {
    }
}

class JobMutatingThroughTraitFromOtherFile implements ShouldQueue
{
    use WritesReportPathFromOtherFile;

    private ?string $reportPath = null;

    public function handle(): void
    {
        $this->rememberReportPath();
    }

    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->reportPath);
    }
}

trait DefaultHandleWritingState
{
    public function handle(): void
    {
        $this->path = '/tmp/default';
        $this->markHandled();
    }
}

class JobOverridingTraitHandle implements ShouldQueue
{
    use DefaultHandleWritingState;

    private ?string $path = null;

    private bool $handled = false;

    public function handle(): void
    {
        logger()->info('custom handle');
    }

    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->path, [$this->handled]);
    }

    private function markHandled(): void
    {
        $this->handled = true;
    }
}

abstract class NonQueuedBaseJob
{
    protected string $stage = 'queued';

    public function handle(): void
    {
        $this->advance();
    }

    public function failed(Throwable $e): void
    {
        logger()->error($this->stage);
    }

    protected function advance(): void
    {
        $this->stage = 'started';
    }
}

class QueuedJobExtendingNonQueuedBase extends NonQueuedBaseJob implements ShouldQueue
{
}

class InvokableUniqueJobMutatingUniqueIdInput implements ShouldQueue, ShouldBeUnique
{
    public function __construct(private string $key)
    {
    }

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function __invoke(): void
    {
        $this->key = 'processed';
    }
}

class JobWithHandleAndInvoke implements ShouldQueue
{
    private ?string $path = null;

    public function handle(): void
    {
    }

    public function __invoke(): void
    {
        $this->path = '/tmp/unused';
    }

    public function failed(Throwable $e): void
    {
        logger()->error((string) $this->path);
    }
}
