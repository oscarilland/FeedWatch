<?php

declare(strict_types=1);

namespace FeedWatch\Tests\Unit\Infrastructure;

use FeedWatch\Infrastructure\Config;
use FeedWatch\Infrastructure\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_missing_required_setting_names_the_setting(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required setting DB_NAME');

        (new Config([]))->require('DB_NAME');
    }

    public function test_invalid_setting_error_does_not_contain_the_value(): void
    {
        $config = new Config(['DB_PORT' => 'secret-looking-value']);

        try {
            $config->requireInt('DB_PORT', 3306);
            self::fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('DB_PORT', $e->getMessage());
            self::assertStringNotContainsString('secret-looking-value', $e->getMessage());
        }
    }

    public function test_unknown_settings_are_ignored(): void
    {
        $config = new Config(['PATH' => '/usr/bin', 'DB_NAME' => 'feedwatch']);

        self::assertSame('', $config->get('PATH'));
        self::assertSame('feedwatch', $config->require('DB_NAME'));
    }

    public function test_empty_values_count_as_missing(): void
    {
        $this->expectException(ConfigException::class);

        (new Config(['DB_USER' => '']))->require('DB_USER');
    }

    public function test_int_setting_falls_back_to_default(): void
    {
        self::assertSame(3306, (new Config([]))->requireInt('DB_PORT', 3306));
        self::assertSame(3307, (new Config(['DB_PORT' => '3307']))->requireInt('DB_PORT', 3306));
    }

    public function test_base_path_is_normalised(): void
    {
        self::assertSame('', (new Config([]))->basePath());
        self::assertSame('', (new Config(['APP_BASE_PATH' => '/']))->basePath());
        self::assertSame('/feedwatch', (new Config(['APP_BASE_PATH' => 'feedwatch/']))->basePath());
        self::assertSame('/demo/feedwatch', (new Config(['APP_BASE_PATH' => '/demo/feedwatch']))->basePath());
    }

    public function test_base_path_with_unsafe_characters_is_rejected(): void
    {
        $this->expectException(ConfigException::class);

        (new Config(['APP_BASE_PATH' => '/feed"><script>']))->basePath();
    }

    public function test_version_defaults_to_dev(): void
    {
        self::assertSame('dev', (new Config([]))->version());
        self::assertSame('abc1234', (new Config(['APP_VERSION' => 'abc1234']))->version());
    }

    public function test_load_prefers_the_generated_config_file(): void
    {
        $dir = sys_get_temp_dir() . '/feedwatch-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.local.php', "<?php return ['APP_VERSION' => 'from-file'];");

        try {
            self::assertSame('from-file', Config::load($dir)->version());
        } finally {
            unlink($dir . '/config.local.php');
            rmdir($dir);
        }
    }

    public function test_load_rejects_a_config_file_that_is_not_an_array(): void
    {
        $dir = sys_get_temp_dir() . '/feedwatch-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.local.php', "<?php return 'oops';");

        try {
            $this->expectException(ConfigException::class);
            Config::load($dir);
        } finally {
            unlink($dir . '/config.local.php');
            rmdir($dir);
        }
    }
}
