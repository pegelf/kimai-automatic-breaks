<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use KimaiPlugin\AutomaticBreaksBundle\Calculation\Block;
use KimaiPlugin\AutomaticBreaksBundle\Calculation\BreakEngine;
use KimaiPlugin\AutomaticBreaksBundle\Notification\MessageFormatter;
use KimaiPlugin\AutomaticBreaksBundle\Notification\Receipt;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

final class NotificationTest extends TestCase
{
    public static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('xlf', new XliffFileLoader());
        foreach (glob(\dirname(__DIR__) . '/Resources/translations/messages.*.xlf') as $file) {
            $translator->addResource('xlf', $file, substr(basename($file), 9, -4));
        }

        return $translator;
    }

    public static function receipt(string $reason = 'rule', int $added = 1800): Receipt
    {
        return new Receipt(str_repeat('a', 32), 123, 456, $reason, $reason === 'rule' ? 21600 : 36000, $added, $added, 45000, 45000, 'ok', $added);
    }

    public function testGermanActualAmountsAndWorkdayWording(): void
    {
        $formatter = new MessageFormatter(self::translator());
        self::assertSame('Deine erfasste Zeit im Arbeitsblock liegt über 6 Stunden. Nach den eingestellten Pausenregeln wurden deinem Eintrag automatisch 30 Minuten Pause hinzugefügt.', $formatter->format(self::receipt(), 'de')['message']);
        self::assertSame('Du hast die maximale Arbeitszeit von 10 Stunden pro Arbeitstag überschritten. Deinem Eintrag wurden deshalb automatisch 2 Stunden und 30 Minuten Pause hinzugefügt.', $formatter->format(self::receipt('maximum', 9000), 'de')['message']);
        self::assertStringContainsString('15 Minuten Pause', $formatter->format(self::receipt('rule', 900), 'de')['message']);
        self::assertSame('0 Sekunden', $formatter->duration(0, 'de'));
        self::assertSame('1 Stunde und 1 Minute und 1 Sekunde', $formatter->duration(3661, 'de'));
    }

    public function testEveryLocaleFormatsMessagesAndPluralDurations(): void
    {
        $translator = self::translator();
        $formatter = new MessageFormatter($translator);
        foreach (glob(\dirname(__DIR__) . '/Resources/translations/messages.*.xlf') as $file) {
            $locale = substr(basename($file), 9, -4);
            foreach (['rule', 'maximum'] as $reason) {
                $message = $formatter->format(self::receipt($reason, 9000), $locale)['message'];
                self::assertStringNotContainsString('%', $message, $locale);
                self::assertStringNotContainsString('automatic_breaks.', $message, $locale);
            }
            foreach (['hours', 'minutes', 'seconds'] as $unit) {
                $pattern = $translator->trans('automatic_breaks.duration.' . $unit, [], 'messages', $locale);
                $icu = new \MessageFormatter($locale, $pattern);
                foreach ([0, 1, 2, 5, 21, 30] as $count) {
                    self::assertNotFalse($icu->format(['count' => $count]), $locale . ': ' . $unit);
                }
            }
        }
    }

    public function testMalformedCustomUnitTranslationCannotFailBookingMessage(): void
    {
        $translator = self::translator();
        $translator->getCatalogue('de')->set('automatic_breaks.duration.minutes', '{invalid', 'messages');
        self::assertStringContainsString('30 minutes', (new MessageFormatter($translator))->format(self::receipt(), 'de')['message']);
    }

    public function testCalculationExplainsRuleAndDailyCapAcrossBlocks(): void
    {
        $engine = new BreakEngine();
        $result = $engine->calculate(new Block(0, 7 * 3600, 900), [], TestPolicies::fixed());
        self::assertSame(['rule', 21600, 900], [$result->reason, $result->threshold, $result->additional]);
        $result = $engine->calculate(new Block(0, 45000, 0), [], TestPolicies::fixed());
        self::assertSame(['maximum', 36000, 9000], [$result->reason, $result->threshold, $result->additional]);
        $result = $engine->calculate(new Block(8 * 3600, 14 * 3600, 0), [new Block(0, 7 * 3600, 1800)], TestPolicies::fixed());
        self::assertSame(['maximum', 9000, 21600, 45000], [$result->reason, $result->additional, $result->blockWork, $result->workdayNet]);
    }
}
