<?php

declare(strict_types=1);

namespace JustLinkIt\Server\Tests;

final class TestRunner
{
    /** @var array<int, array{name: string, fn: callable}> */
    private array $cases = [];
    private int $passed = 0;
    private int $failed = 0;

    public function test(string $name, callable $fn): void
    {
        $this->cases[] = ['name' => $name, 'fn' => $fn];
    }

    public function run(): int
    {
        foreach ($this->cases as $case) {
            try {
                ($case['fn'])($this);
                $this->passed++;
                echo "  OK   {$case['name']}\n";
            } catch (\Throwable $e) {
                $this->failed++;
                echo "  FAIL {$case['name']}: {$e->getMessage()}\n";
            }
        }

        $total = $this->passed + $this->failed;
        echo "\n{$this->passed}/{$total} passed\n";

        return $this->failed === 0 ? 0 : 1;
    }

    public function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $message = $message !== ''
                ? $message
                : sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new \RuntimeException($message);
        }
    }

    /**
     * @param class-string<\Throwable> $expectedClass
     */
    public function assertThrows(string $expectedClass, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $expectedClass) {
                return;
            }
            throw new \RuntimeException(
                $message !== '' ? $message : sprintf('Expected %s, got %s', $expectedClass, get_class($e))
            );
        }

        throw new \RuntimeException($message !== '' ? $message : "Expected {$expectedClass} to be thrown, none was");
    }
}
