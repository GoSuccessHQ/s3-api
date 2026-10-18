<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Support;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionFunction;
use SplFileInfo;

/**
 * Tells which PHP extension the functions and constants of some code belong
 * to, for the tests that keep the package independent of optional extensions.
 */
final class PhpExtensions
{
    /**
     * The extensions that are part of every PHP 8.4: they cannot be disabled.
     */
    public const array ALWAYS_AVAILABLE = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard'];

    /**
     * Tokens after which a name is no global function or constant.
     */
    private const array NOT_GLOBAL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST];

    /**
     * The lower-case names of the `ext-*` requirements of a package.
     *
     * @param array<array-key, mixed> $package The data of its composer.json.
     *
     * @return list<string>
     */
    public static function requiredBy(array $package, string $section = 'require'): array
    {
        $requirements = $package[$section] ?? [];
        $extensions = [];

        foreach (\is_array($requirements) ? array_keys($requirements) : [] as $name) {
            if (\is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[] = strtolower(substr($name, 4));
            }
        }

        return $extensions;
    }

    /**
     * The lower-case extension of a defined function, or "a polyfill" for one
     * written in PHP, which stands in for an extension that is not loaded.
     */
    public static function ofFunction(string $name): string
    {
        $reflection = new ReflectionFunction($name);
        $extension = $reflection->getExtensionName();

        return $reflection->isInternal() && $extension !== false ? strtolower($extension) : 'a polyfill';
    }

    /**
     * @return array<string, string> The lower-case extension of each constant the
     *                               running PHP defines, by name.
     */
    public static function ofConstants(): array
    {
        $constants = [];

        foreach (get_defined_constants(true) as $extension => $names) {
            if ($extension === 'user') {
                continue;
            }

            foreach (array_keys($names) as $name) {
                $constants[$name] = strtolower($extension);
            }
        }

        return $constants;
    }

    /**
     * The global names some code refers to, without a leading backslash, and
     * whether each is called: its functions and constants, among other names.
     *
     * @return list<array{string, bool}>
     */
    public static function namesUsedIn(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $token): bool => !$token->isIgnorable()));
        $names = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            if (($tokens[$index - 1] ?? null)?->is(self::NOT_GLOBAL) === true) {
                continue;
            }

            $names[] = [ltrim($token->text, '\\'), ($tokens[$index + 1] ?? null)?->text === '('];
        }

        return $names;
    }

    /**
     * @param list<string> $directories Relative to the repository.
     *
     * @return iterable<string, string> The code of the PHP files, by path relative
     *                                  to the repository.
     */
    public static function sourceFiles(array $directories): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach ($directories as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    yield substr($file->getPathname(), \strlen($root) + 1) => (string) file_get_contents($file->getPathname());
                }
            }
        }
    }

    /**
     * @return array<array-key, mixed> The data of a JSON file of the repository.
     */
    public static function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . "/{$path}"), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }
}
