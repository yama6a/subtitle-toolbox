<?php

declare(strict_types=1);

namespace SubtitleToolbox\Http;

/**
 * Runs PHP in a subprocess with curl_init disabled, as on a PHP without ext-curl.
 */
final class WithoutCurl
{
    /**
     * @param list<string> $arguments the arguments of php after the ini setting
     *
     * @return array{int, string, string} exit code, standard output, standard error
     */
    public static function run(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, "-d", "disable_functions=curl_init", ...$arguments],
            [0 => ["file", PHP_OS_FAMILY === "Windows" ? "NUL" : "/dev/null", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }


    /**
     * Runs $code with the autoloader of the package and returns the exception that it throws, with its file and line.
     */
    public static function exception(string $code): \Throwable
    {
        $autoload = var_export(dirname(__DIR__, 2) . "/vendor/autoload.php", true);
        [, $stdout, $stderr] = self::run(["-r", "require $autoload; try { $code } catch (\\Throwable \$e) { echo serialize(\$e); }"]);
        $exception = @unserialize($stdout);
        if (!$exception instanceof \Throwable) {
            throw new \RuntimeException("The code threw no exception. Output: $stdout$stderr");
        }

        return $exception;
    }
}
