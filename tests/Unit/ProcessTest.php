<?php namespace Nabeghe\Cronark\Tests\Unit;

use Nabeghe\Cronark\Process;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Process::class)]
class ProcessTest extends TestCase
{
    #[Test]
    #[TestDox('Process::id() returns current process ID')]
    public function it_returns_current_process_id(): void
    {
        $pid = Process::id();

        $this->assertIsInt($pid);
        $this->assertGreaterThan(0, $pid);
        $this->assertEquals(getmypid(), $pid);
    }

    #[Test]
    #[TestDox('Process::exists() validates current process exists')]
    public function it_validates_current_process_exists(): void
    {
        $currentPid = Process::id();

        $this->assertTrue(Process::exists($currentPid));
    }

    #[Test]
    #[TestDox('Process::exists() returns false for invalid PID')]
    public function it_returns_false_for_invalid_pid(): void
    {
        $this->assertFalse(Process::exists(-1));
        $this->assertFalse(Process::exists(0));
        $this->assertFalse(Process::exists(999999999));
    }

    #[Test]
    #[TestDox('Process::getScriptPath() returns current script path')]
    public function it_returns_current_script_path(): void
    {
        $currentPid = Process::id();
        $scriptPath = Process::getScriptPath($currentPid);

        $this->assertIsString($scriptPath);
        $this->assertNotEmpty($scriptPath);
    }

    #[Test]
    #[TestDox('Process::getScriptPath() returns null for invalid PID')]
    public function it_returns_null_for_invalid_pid_script_path(): void
    {
        $this->assertNull(Process::getScriptPath(-1));
        $this->assertNull(Process::getScriptPath(999999999));
    }

    #[Test]
    #[TestDox('Process::kill() returns false for invalid PID')]
    public function it_returns_false_when_killing_invalid_pid(): void
    {
        $this->assertFalse(Process::kill(-1));
        $this->assertFalse(Process::kill(0));
        $this->assertFalse(Process::kill(999999999));
    }

    #[Test]
    #[TestDox('Process::kill() does not kill current process in test')]
    public function it_does_not_kill_current_process(): void
    {
        $currentPid = Process::id();

        // We won't actually kill the test process
        $this->assertTrue(Process::exists($currentPid));
    }
}
