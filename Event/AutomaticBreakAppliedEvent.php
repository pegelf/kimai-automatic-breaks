<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Event;

use KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt;
use Symfony\Contracts\EventDispatcher\Event;

/** Emitted once per deduction in this process, after the owning transaction has committed. */
final class AutomaticBreakAppliedEvent extends Event
{
    public function __construct(public readonly Receipt $receipt)
    {
    }
}
