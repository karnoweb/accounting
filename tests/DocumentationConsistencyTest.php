<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lightweight guards against documentation drift. Not a substitute for reading the code.
 */
class DocumentationConsistencyTest extends PHPUnitTestCase
{
    private function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    public function test_composer_version_is_present(): void
    {
        $composer = json_decode((string) file_get_contents($this->packageRoot().'/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertArrayHasKey('version', $composer);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $composer['version']);
    }

    public function test_current_docs_do_not_hardcode_superseded_versions_as_current(): void
    {
        $forbidden = [
            'نسخه فعلی پکیج `13.4',
            'نسخه فعلی: 13.4',
            'Package version: **13.4',
            'نسخهٔ پکیج: **۱۳.۸',
            'نسخهٔ پکیج: **۱۳.۴',
            'README said current version',
        ];

        foreach ($this->currentDocFiles() as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    basename($file).' still presents a stale package version as current.'
                );
            }
        }
    }

    public function test_current_docs_do_not_repeat_known_stale_claims(): void
    {
        $forbidden = [
            'جدول دورهٔ ماهانه وجود ندارد',
            'فیلتر مستقل بر اساس مرکز هزینه ندارند',
            'trialBalance(branchId:',
        ];

        foreach ($this->currentDocFiles() as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    basename($file).' still contains a known stale claim: '.$needle
                );
            }
        }
    }

    public function test_relative_markdown_links_resolve(): void
    {
        $broken = [];

        foreach ($this->currentDocFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (! preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ($target === '' || str_starts_with($target, '#') || preg_match('#^(https?:|mailto:|tel:)#i', $target)) {
                    continue;
                }

                $path = explode('#', $target, 2)[0];
                if ($path === '') {
                    continue;
                }

                $resolved = realpath(dirname($file).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
                if ($resolved === false || ! file_exists($resolved)) {
                    $broken[] = basename(dirname($file)).'/'.basename($file).' → '.$target;
                }
            }
        }

        $this->assertSame([], $broken, "Broken relative markdown links:\n".implode("\n", $broken));
    }

    public function test_published_config_keys_are_mentioned_in_configuration_doc(): void
    {
        $config = require $this->packageRoot().'/config/accounting.php';
        $this->assertIsArray($config);

        $doc = (string) file_get_contents($this->packageRoot().'/docs/06-configuration.md');
        $missing = [];

        foreach ($this->flattenConfigKeys($config) as $key) {
            $leaf = substr($key, strrpos($key, '.') === false ? 0 : strrpos($key, '.') + 1);
            if (! str_contains($doc, '`'.$leaf.'`') && ! str_contains($doc, $leaf)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'Config keys missing from docs/06-configuration.md: '.implode(', ', $missing));
    }

    public function test_php_code_fences_in_current_docs_reference_existing_classes(): void
    {
        $missing = [];

        foreach ($this->currentDocFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (! preg_match_all('/```php\s*(.*?)```/s', $contents, $blocks)) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all('/\bKarnoweb\\\\Accounting\\\\[A-Za-z0-9\\\\]+/', $block, $matches)) {
                    foreach ($matches[0] as $class) {
                        $class = str_replace('\\\\', '\\', $class);
                        $class = rtrim($class, '\\');
                        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                            $missing[] = basename($file).': '.$class;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Documented classes that do not exist:\n".implode("\n", $missing));
    }

    /**
     * @return list<string>
     */
    private function currentDocFiles(): array
    {
        $roots = [
            $this->packageRoot().'/README.md',
            $this->packageRoot().'/docs',
        ];

        $files = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                if (! $info->isFile() || $info->getExtension() !== 'md') {
                    continue;
                }

                $path = $info->getPathname();
                if (str_contains($path, DIRECTORY_SEPARATOR.'14-implementation'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if (str_contains($path, DIRECTORY_SEPARATOR.'examples'.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if (str_ends_with($path, 'reporting-implementation.md')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function flattenConfigKeys(array $config, string $prefix = ''): array
    {
        $keys = [];

        foreach ($config as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== [] && $this->isAssoc($value)) {
                $keys = array_merge($keys, $this->flattenConfigKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
