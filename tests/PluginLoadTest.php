<?php

use PHPUnit\Framework\TestCase;

final class PluginLoadTest extends TestCase
{
    public function testRunsOnTheVerifiedRoundcubeRelease(): void
    {
        $this->assertSame(zemzemeh_dlp::SUPPORTED_VERSION, RCMAIL_VERSION);
    }

    public function testRoundcubeLoadsThePluginBeforeItsRequiredAttachmentStorage(): void
    {
        $plugins = rcmail::get_instance()->plugins;
        $loaded = $plugins->loaded_plugins();

        $this->assertInstanceOf(zemzemeh_dlp::class, $plugins->get_plugin('zemzemeh_dlp'));
        // Configured plugins run first, so this plugin's denials can stop filesystem_attachments.
        $this->assertLessThan(array_search('filesystem_attachments', $loaded, true),
            array_search('zemzemeh_dlp', $loaded, true));
    }
}
