<?php

namespace Modules\AiChatPanel\Tests;

/**
 * Guards the module against PHP version drift between dev and production.
 *
 * Laravel's error handler converts every E_DEPRECATED into an ErrorException,
 * and ErrorException is an \Exception — so a deprecation raised inside any of
 * the module's broad `catch (\Exception)` blocks does not surface as a
 * deprecation. It is swallowed and reported as "the connection test failed
 * unexpectedly" / "something went wrong", with the real cause only in the app
 * log. On a production box running PHP 8.5 this took out every request the
 * panel makes, because curl_close() is called on every single one.
 *
 * The dev container runs 8.3 and never sees any of it. These checks are static,
 * so they hold whatever the running interpreter is.
 *
 * composer.json declares php >=7.1.0, which is the range that has to stay
 * clean — not just whatever the container happens to ship.
 */
class PhpCompatibilityTest extends AiChatPanelTestCase
{
    /**
     * curl_close() is a no-op since PHP 8.0 and deprecated in 8.5.
     *
     * It still does something on PHP 7, which we support, so the call is not
     * banned outright — it has to sit behind a version guard.
     *
     * @return void
     */
    public function testCurlCloseIsVersionGuarded()
    {
        foreach ($this->sources() as $path => $code) {
            foreach ($this->linesMatching($code, '/\bcurl_close\s*\(/') as $number => $line) {
                $this->assertMatchesRegularExpression(
                    '/PHP_VERSION_ID\s*<\s*80000/',
                    $this->contextAround($code, $number),
                    $path.':'.$number.' calls curl_close() without a PHP_VERSION_ID < 80000 guard. '
                        .'On PHP 8.5 that raises a deprecation, which Laravel turns into an '
                        .'ErrorException and the controller swallows.'
                );
            }
        }
    }

    /**
     * `array $x = null` is an implicit nullable, deprecated since PHP 8.4.
     *
     * The explicit form `?array $x = null` means the same thing and parses on
     * PHP 7.1, so there is never a reason to write the implicit one.
     *
     * @return void
     */
    public function testNoImplicitlyNullableParameters()
    {
        $types = 'array|string|int|float|bool|callable|iterable|object|self|parent|\\\\?[A-Z][A-Za-z0-9_\\\\]*';

        foreach ($this->sources() as $path => $code) {
            $pattern = '/(?<![?|\w])(?:'.$types.')\s+\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*null/';

            foreach ($this->linesMatching($code, $pattern) as $number => $line) {
                // Only parameter lists matter; a property default or an
                // assignment in a body is not affected.
                if (strpos($line, 'function') === false && strpos($line, '$this') !== false) {
                    continue;
                }

                $this->fail(
                    $path.':'.$number.' declares an implicitly nullable parameter: '.trim($line)."\n"
                        .'Write the type as ?Type instead. PHP 8.4 deprecates the implicit form.'
                );
            }
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Every PHP file the module ships, excluding its own tests.
     *
     * @return array path => source
     */
    protected function sources()
    {
        $root = realpath(__DIR__.'/..');

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $sources = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

            if (strpos($relative, 'Tests/') === 0 || strpos($relative, 'vendor/') === 0) {
                continue;
            }

            $sources[$relative] = file_get_contents($file->getPathname());
        }

        $this->assertNotEmpty($sources, 'Found no module sources to check.');

        return $sources;
    }

    /**
     * @param string $code
     * @param string $pattern
     *
     * @return array 1-based line number => line
     */
    protected function linesMatching($code, $pattern)
    {
        $matches = [];

        foreach (explode("\n", $code) as $index => $line) {
            // Skip comment lines: this file and the guard's own docblock talk
            // about curl_close() without calling it.
            $trimmed = ltrim($line);

            if ($trimmed === '' || $trimmed[0] === '*' || strpos($trimmed, '//') === 0 || strpos($trimmed, '/*') === 0) {
                continue;
            }

            if (preg_match($pattern, $line)) {
                $matches[$index + 1] = $line;
            }
        }

        return $matches;
    }

    /**
     * A few lines either side, which is where a version guard would sit.
     *
     * @param string $code
     * @param int    $number 1-based
     *
     * @return string
     */
    protected function contextAround($code, $number, $radius = 4)
    {
        $lines = explode("\n", $code);
        $start = max(0, $number - 1 - $radius);

        return implode("\n", array_slice($lines, $start, $radius * 2 + 1));
    }
}
