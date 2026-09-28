<?php

declare(strict_types=1);

namespace Tests\Rules\Queue\Data;

trait WritesReportPathFromOtherFile
{
    private function rememberReportPath(): void
    {
        $this->reportPath = '/tmp/report';
    }
}
