<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use GoSuccess\S3\Tests\Support\PhpExtensions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The runtime may only use what every PHP 8.4 has and the extensions that
 * composer.json requires. A call into another extension works in development,
 * where the extension or a polyfill of a dev dependency is loaded, but fails
 * with an Error on a server without it.
 */
#[CoversNothing]
final class RequirementsTest extends TestCase
{
    public function testTheRuntimeUsesOnlyCorePhpAndTheRequiredExtensions(): void
    {
        $allowed = [...PhpExtensions::ALWAYS_AVAILABLE, ...PhpExtensions::requiredBy(PhpExtensions::readJson('composer.json'))];
        $constants = PhpExtensions::ofConstants();
        $violations = [];

        foreach (PhpExtensions::sourceFiles(['src']) as $path => $code) {
            foreach (PhpExtensions::namesUsedIn($code) as [$name, $isCall]) {
                $extension = match (true) {
                    $isCall && \function_exists($name) => PhpExtensions::ofFunction($name),
                    !$isCall => $constants[$name] ?? null,
                    default => null,
                };

                if ($extension !== null && !\in_array($extension, $allowed, true)) {
                    $violations[] = "{$path}: {$name} ({$extension})";
                }
            }

            foreach (self::importedClasses($code) as $class) {
                if (!class_exists($class, false) && !interface_exists($class, false) && !enum_exists($class, false)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                $extension = $reflection->getExtensionName();

                if ($reflection->isInternal() && $extension !== false && !\in_array(strtolower($extension), $allowed, true)) {
                    $violations[] = "{$path}: {$class} ({$extension})";
                }
            }
        }

        self::assertSame([], $violations, 'The runtime uses extensions that composer.json does not require.');
    }

    /**
     * @return list<string> The classes imported with a `use` statement.
     */
    private static function importedClasses(string $code): array
    {
        preg_match_all('/^use ([\w\\\\]+)(?: as \w+)?;$/m', $code, $matches);

        return $matches[1];
    }
}
