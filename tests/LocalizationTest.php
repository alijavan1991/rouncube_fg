<?php

use PHPUnit\Framework\TestCase;

final class LocalizationTest extends TestCase
{
    private static function strings(string $locale): array
    {
        $labels = $messages = [];
        include ZZDLP_PLUGIN_DIR . "localization/{$locale}.inc";

        return [array_keys($labels), array_keys($messages)];
    }

    public function testEnglishAndPersianDefineTheSameStrings(): void
    {
        $this->assertSame(self::strings('en_US'), self::strings('fa_IR'));
    }

    public function testEveryRefusalReasonHasAMessage(): void
    {
        $plugin = rcmail::get_instance()->plugins->get_plugin('zemzemeh_dlp');
        $message = new ReflectionMethod($plugin, 'error_message');
        foreach (['auth', 'not_found', 'too_large', 'changed', 'inactive', 'forged', 'unavailable', 'storage'] as $reason) {
            $this->assertStringStartsNotWith('[', $message->invoke($plugin, $reason), $reason);
        }
    }
}
