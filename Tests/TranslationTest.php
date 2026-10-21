<?php

namespace KimaiPlugin\AutomaticBreaksBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;

final class TranslationTest extends TestCase
{
    public function testEveryKimaiLanguageHasCompleteTranslations(): void
    {
        $root = \dirname(__DIR__, 4);
        $resources = \dirname(__DIR__) . '/Resources/translations';
        $loader = new XliffFileLoader();
        $english = $loader->load($resources . '/messages.en.xlf', 'en')->all('messages');
        $newKeys = array_filter(array_keys($english), static fn (string $key): bool => \in_array($key, [
            'automatic_breaks.breaks_disabled',
            'automatic_breaks.mode',
            'automatic_breaks.mode.fixed',
            'automatic_breaks.mode.capped',
            'automatic_breaks.help.mode',
            'automatic_breaks.rules',
            'automatic_breaks.rules_help',
            'automatic_breaks.add_rule',
            'automatic_breaks.remove_rule',
            'automatic_breaks.sorted',
            'automatic_breaks.grouping',
            'automatic_breaks.grouping_help',
        ], true));
        foreach (glob($root . '/translations/messages.*.xlf') as $source) {
            $locale = substr(basename($source), 9, -4);
            $file = $resources . '/messages.' . $locale . '.xlf';
            self::assertFileExists($file, $locale);
            $actual = $loader->load($file, $locale)->all('messages');
            // New settings copy uses Symfony's English fallback until localized.
            $required = \in_array($locale, ['en', 'de', 'de_CH'], true) ? array_keys($english) : array_diff(array_keys($english), $newKeys);
            self::assertSame(array_values($required), array_keys($actual), $locale);
            foreach ($actual as $key => $value) {
                self::assertNotSame('', trim($value), $locale . ': ' . $key);
                self::assertNotSame($key, $value, $locale . ': ' . $key);
            }
        }
    }
}
