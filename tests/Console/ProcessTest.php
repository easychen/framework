<?php

namespace Illuminate\Tests;

use Illuminate\Console\Process\Exceptions\ProcessFailedException;
use Illuminate\Console\Process\Factory;
use Illuminate\Console\Process\Pool;
use Illuminate\Contracts\Console\Process\ProcessResult;
use Mockery as m;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testSuccessfulProcess()
    {
        $factory = new Factory;
        $result = $factory->path(__DIR__)->run($this->ls());

        $this->assertInstanceOf(ProcessResult::class, $result);
        $this->assertTrue($result->successful());
        $this->assertFalse($result->failed());
        $this->assertEquals(0, $result->exitCode());
        $this->assertTrue(str_contains($result->output(), 'ProcessTest.php'));
        $this->assertEquals('', $result->errorOutput());

        $result->throw();
        $result->throwIf(true);
    }

    public function testProcessPool()
    {
        $factory = new Factory;

        $pool = $factory->pool(function (Pool $pool) {
            return [
                $pool->path(__DIR__)->command($this->ls()),
                $pool->path(__DIR__)->command($this->ls()),
            ];
        });

        $this->assertTrue(count($pool->running()) === 0);

        $pool->start();
        $pool->wait();

        $this->assertTrue($pool[0]->successful());
        $this->assertTrue($pool[1]->successful());

        $this->assertTrue(str_contains($pool[0]->output(), 'ProcessTest.php'));
        $this->assertTrue(str_contains($pool[1]->output(), 'ProcessTest.php'));
    }

    public function testProcessPoolResultsCanBeEvaluatedOnTime()
    {
        $factory = new Factory;

        $pool = $factory->pool(function (Pool $pool) {
            return [
                $pool->path(__DIR__)->command($this->ls()),
                $pool->path(__DIR__)->command($this->ls()),
            ];
        });

        $this->assertTrue($pool[0]->successful());
        $this->assertTrue($pool[1]->successful());

        $this->assertTrue(str_contains($pool[0]->output(), 'ProcessTest.php'));
        $this->assertTrue(str_contains($pool[1]->output(), 'ProcessTest.php'));
    }

    public function testProcessPoolResultsCanBeEvaluatedByName()
    {
        $factory = new Factory;

        $pool = $factory->pool(function (Pool $pool) {
            return [
                $pool->as('first')->path(__DIR__)->command($this->ls()),
                $pool->as('second')->path(__DIR__)->command($this->ls()),
            ];
        });

        $this->assertTrue($pool['first']->successful());
        $this->assertTrue($pool['second']->successful());

        $this->assertTrue(str_contains($pool['first']->output(), 'ProcessTest.php'));
        $this->assertTrue(str_contains($pool['second']->output(), 'ProcessTest.php'));
    }

    public function testOutputCanBeRetrievedViaStartCallback()
    {
        $factory = new Factory;

        $output = [];

        $process = $factory->path(__DIR__)->start($this->ls(), function ($type, $buffer) use (&$output) {
            $output[] = $buffer;
        });

        $process->wait();

        $this->assertTrue(str_contains(implode('', $output), 'ProcessTest.php'));
    }

    public function testOutputCanBeRetrievedViaWaitCallback()
    {
        $factory = new Factory;

        $output = [];

        $process = $factory->path(__DIR__)->start($this->ls());

        $process->wait(function ($type, $buffer) use (&$output) {
            $output[] = $buffer;
        });

        $this->assertTrue(str_contains(implode('', $output), 'ProcessTest.php'));
    }

    public function testBasicProcessFake()
    {
        $factory = new Factory;
        $factory->fake();

        $result = $factory->run('ls -la');

        $this->assertEquals('', $result->output());
        $this->assertEquals('', $result->errorOutput());
        $this->assertEquals(0, $result->exitCode());
        $this->assertTrue($result->successful());
    }

    public function testProcessFakeExitCodes()
    {
        $factory = new Factory;
        $factory->fake(fn () => $factory->result('test output', exitCode: 1));

        $result = $factory->run('ls -la');
        $this->assertFalse($result->successful());
    }

    public function testBasicProcessFakeWithCustomOutput()
    {
        $factory = new Factory;
        $factory->fake(fn () => $factory->result('test output'));

        $result = $factory->run('ls -la');
        $this->assertEquals("test output\n", $result->output());

        // Array of output...
        $factory = new Factory;
        $factory->fake(fn () => $factory->result(['line 1', 'line 2']));

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\nline 2\n", $result->output());

        // Array of output with empty line...
        $factory = new Factory;
        $factory->fake(fn () => $factory->result(['line 1', '', 'line 2']));

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\n\nline 2\n", $result->output());

        // Plain string...
        $factory = new Factory;
        $factory->fake(fn () => 'test output');

        $result = $factory->run('ls -la');
        $this->assertEquals("test output\n", $result->output());

        // Plain array...
        $factory = new Factory;
        $factory->fake(fn () => ['line 1', 'line 2']);

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\nline 2\n", $result->output());

        // Plain array with empty line...
        $factory = new Factory;
        $factory->fake(fn () => ['line 1', '', 'line 2']);

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\n\nline 2\n", $result->output());

        // Process description...
        $factory = new Factory;
        $factory->fake(fn () => $factory->describe()->output('line 1')->output('line 2'));

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\nline 2\n", $result->output());

        // Process description with empty line...
        $factory = new Factory;
        $factory->fake(fn () => $factory->describe()->output('line 1')->output('')->output('line 2'));

        $result = $factory->run('ls -la');
        $this->assertEquals("line 1\n\nline 2\n", $result->output());
    }

    public function testProcessFakeWithErrorOutput()
    {
        $factory = new Factory;
        $factory->fake(fn () => $factory->result('standard output', 'error output'));

        $result = $factory->run('ls -la');
        $this->assertEquals("standard output\n", $result->output());
        $this->assertEquals("error output\n", $result->errorOutput());

        // Array of error output...
        $factory = new Factory;
        $factory->fake(fn () => $factory->result('standard output', ['line 1', 'line 2']));

        $result = $factory->run('ls -la');
        $this->assertEquals("standard output\n", $result->output());
        $this->assertEquals("line 1\nline 2\n", $result->errorOutput());

        // Using process description...
        $factory = new Factory;
        $factory->fake(fn () => $factory->describe()->output('standard output')->errorOutput('error output'));

        $result = $factory->run('ls -la');
        $this->assertEquals("standard output\n", $result->output());
        $this->assertEquals("error output\n", $result->errorOutput());
    }

    public function testCustomizedFakesPerCommand()
    {
        $factory = new Factory;

        $factory->fake([
            'ls *' => 'ls command',
            'cat *' => 'cat command',
        ]);

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command\n", $result->output());

        $result = $factory->run('cat composer.json');
        $this->assertEquals("cat command\n", $result->output());
    }

    public function testProcessFakeSequences()
    {
        $factory = new Factory;

        $factory->fake([
            'ls *' => $factory->sequence()
                        ->push('ls command 1')
                        ->push('ls command 2'),
            'cat *' => 'cat command',
        ]);

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 1\n", $result->output());

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 2\n", $result->output());

        $result = $factory->run('cat composer.json');
        $this->assertEquals("cat command\n", $result->output());
    }

    public function testProcessFakeSequencesCanReturnEmptyResultsWhenSequenceIsEmpty()
    {
        $factory = new Factory;

        $factory->fake([
            'ls *' => $factory->sequence()
                        ->push('ls command 1')
                        ->push('ls command 2')
                        ->dontFailWhenEmpty(),
        ]);

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 1\n", $result->output());

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 2\n", $result->output());

        $result = $factory->run('ls -la');
        $this->assertEquals("", $result->output());
    }

    public function testProcessFakeSequencesCanThrowWhenSequenceIsEmpty()
    {
        $this->expectException(OutOfBoundsException::class);

        $factory = new Factory;

        $factory->fake([
            'ls *' => $factory->sequence()
                        ->push('ls command 1')
                        ->push('ls command 2')
        ]);

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 1\n", $result->output());

        $result = $factory->run('ls -la');
        $this->assertEquals("ls command 2\n", $result->output());

        $result = $factory->run('ls -la');
    }

    public function testStrayProcessesCanBePrevented()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Attempted process');

        $factory = new Factory;

        $factory->preventStrayProcesses();

        $factory->fake([
            'ls *' => 'ls command',
        ]);

        $result = $factory->run('cat composer.json');
    }

    public function testStrayProcessesActuallyRunByDefault()
    {
        $factory = new Factory;

        $factory->fake([
            'cat *' => 'cat command',
        ]);

        $result = $factory->path(__DIR__)->run($this->ls());
        $this->assertTrue(str_contains($result->output(), 'ProcessTest.php'));
    }

    public function testFakeProcessesCanThrow()
    {
        $this->expectException(ProcessFailedException::class);

        $factory = new Factory;

        $factory->fake(fn () => $factory->result(exitCode: 1));

        $result = $factory->path(__DIR__)->run($this->ls());
        $result->throw();
    }

    public function testFakeProcessesThrowIfTrue()
    {
        $this->expectException(ProcessFailedException::class);

        $factory = new Factory;

        $factory->fake(fn () => $factory->result(exitCode: 1));

        $result = $factory->path(__DIR__)->run($this->ls());
        $result->throwIf(true);
    }

    public function testFakeProcessesDontThrowIfFalse()
    {
        $factory = new Factory;

        $factory->fake(fn () => $factory->result(exitCode: 1));

        $result = $factory->path(__DIR__)->run($this->ls());
        $result->throwIf(false);

        $this->assertTrue(true);
    }

    public function testRealProcessesCanHaveErrorOutput()
    {
        if (windows_os()) {
            $this->markTestSkipped('Requires Linux.');
        }

        $factory = new Factory;
        $result = $factory->path(__DIR__)->run('echo "Hello World" >&2; exit 1;');

        $this->assertFalse($result->successful());
        $this->assertEquals("", $result->output());
        $this->assertEquals("Hello World\n", $result->errorOutput());
    }

    public function testRealProcessesCanThrow()
    {
        if (windows_os()) {
            $this->markTestSkipped('Requires Linux.');
        }

        $this->expectException(ProcessFailedException::class);

        $factory = new Factory;
        $result = $factory->path(__DIR__)->run('echo "Hello World" >&2; exit 1;');

        $result->throw();
    }

    public function testRealProcessesCanThrowIfTrue()
    {
        if (windows_os()) {
            $this->markTestSkipped('Requires Linux.');
        }

        $this->expectException(ProcessFailedException::class);

        $factory = new Factory;
        $result = $factory->path(__DIR__)->run('echo "Hello World" >&2; exit 1;');

        $result->throwIf(true);
    }

    public function testRealProcessesDoesntThrowIfFalse()
    {
        if (windows_os()) {
            $this->markTestSkipped('Requires Linux.');
        }

        $factory = new Factory;
        $result = $factory->path(__DIR__)->run('echo "Hello World" >&2; exit 1;');

        $result->throwIf(false);

        $this->assertTrue(true);
    }

    protected function ls()
    {
        return windows_os() ? 'dir' : 'ls';
    }
}
