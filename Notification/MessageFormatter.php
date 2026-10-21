<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Notification;

use Symfony\Contracts\Translation\TranslatorInterface;

final class MessageFormatter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function duration(int $seconds, string $locale): string
    {
        $parts = [];
        foreach (['hours' => intdiv($seconds, 3600), 'minutes' => intdiv($seconds % 3600, 60), 'seconds' => $seconds % 60] as $unit => $number) {
            if ($number > 0 || ($seconds === 0 && $unit === 'seconds')) {
                $pattern = $this->translator->trans('automatic_breaks.duration.' . $unit, [], 'messages', $locale);
                try {
                    $text = (new \MessageFormatter($locale, $pattern))->format(['count' => $number]);
                } catch (\Throwable) {
                    $text = false;
                }
                // A malformed user translation must never turn a successful booking into an error.
                $parts[] = $text !== false ? $text : (string) $number . ' ' . $unit;
            }
        }

        return implode($this->translator->trans('automatic_breaks.duration.separator', [], 'messages', $locale), $parts);
    }

    /** @return array<string, int|string> */
    public function format(Receipt $receipt, string $locale): array
    {
        $message = $this->translator->trans('automatic_breaks.notice.' . $receipt->reason, [
            '%threshold%' => $this->duration($receipt->thresholdSeconds, $locale),
            '%added%' => $this->duration($receipt->addedSeconds, $locale),
            '%total%' => $this->duration($receipt->totalBreakSeconds, $locale),
            '%entry%' => (string) $receipt->timesheetId,
        ], 'messages', $locale);
        if ($receipt->status === 'limited') {
            $message .= ' ' . $this->translator->trans('automatic_breaks.review', [], 'messages', $locale);
        }

        return [
            'id' => $receipt->id,
            'timesheetId' => $receipt->timesheetId,
            'userId' => $receipt->userId,
            'reason' => $receipt->reason,
            'thresholdSeconds' => $receipt->thresholdSeconds,
            'addedSeconds' => $receipt->addedSeconds,
            'totalBreakSeconds' => $receipt->totalBreakSeconds,
            'blockWorkSeconds' => $receipt->blockWorkSeconds,
            'workdayNetSeconds' => $receipt->workdayNetSeconds,
            'status' => $receipt->status,
            'currentBreakSeconds' => $receipt->currentBreakSeconds,
            'closeLabel' => $this->translator->trans('action.close', [], 'messages', $locale),
            'version' => 1,
            'title' => $this->translator->trans('automatic_breaks.title', [], 'messages', $locale),
            'message' => $message,
            'locale' => $locale,
        ];
    }
}
