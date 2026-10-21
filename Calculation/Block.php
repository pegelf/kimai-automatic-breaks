<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Calculation;

final readonly class Block
{
    public function __construct(public int $begin, public int $end, public int $break = 0)
    {
    }

    public function gross(): int
    {
        return max(0, $this->end - $this->begin);
    }

    public function net(): int
    {
        return max(0, $this->gross() - $this->break);
    }
}
