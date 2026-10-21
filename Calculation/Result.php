<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Calculation;

final readonly class Result
{
    public function __construct(
        public int $additional = 0,
        public string $status = 'ok',
        public string $reason = 'none',
        public int $threshold = 0,
        public int $blockWork = 0,
        public int $workdayNet = 0,
    ) {
    }
}
