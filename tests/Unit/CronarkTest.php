<?php namespace Nabeghe\Cronark\Tests\Unit;

use Nabeghe\Cronark\Cronark;
use Nabeghe\Cronark\Process;
use Nabeghe\Cronark\Storage;
use Nabeghe\Cronark\StorageInterface;
use Nabeghe\Cronark\Tests\Fixtures\FailingJob;
use Nabeghe\Cronark\Tests\Fixtures\TestJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cronark::class)]
class CronarkTest extends TestCase
{
    private Cronark $cronark;
    private StorageInterface $storage;
    private string $testStoragePath;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cronark_test_'.uniqid();
        $this->storage = new Storage($this->testStoragePath);
        $this->cronark = new Cronark($this->storage);

        // Reset job counters
        TestJob::reset();
        FailingJob::reset();
    }

    protected function tearDown(): void
    {
        // Clean up
        if (is_dir($this->testStoragePath)) {
            $files = glob($this->testStoragePath.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testStoragePath);
        }
    }

    #[Test]
    #[TestDox('Cronark can be instantiated')]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(Cronark::class, $this->cronark);
    }

    #[Test]
    #[TestDox('registerWorker() creates empty worker')]
    public function it_registers_worker(): void
    {
        $this->cronark->registerWorker('test_worker');

        $this->assertFalse($this->cronark->hasAnyJob('test_worker'));
        $this->assertEquals(0, $this->cronark->getJobsCount('test_worker'));
    }

    #[Test]
    #[TestDox('addJob() adds job to worker')]
    public function it_adds_job_to_worker(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->assertTrue($this->cronark->hasAnyJob('test_worker'));
        $this->assertEquals(1, $this->cronark->getJobsCount('test_worker'));
    }

    #[Test]
    #[TestDox('addJob() adds multiple jobs')]
    public function it_adds_multiple_jobs(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->assertEquals(3, $this->cronark->getJobsCount('test_worker'));
    }

    #[Test]
    #[TestDox('addJob() inserts job at specific position')]
    public function it_inserts_job_at_position(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker', 1);

        $this->assertEquals(TestJob::class, $this->cronark->getJob(0, 'test_worker'));
        $this->assertEquals(TestJob::class, $this->cronark->getJob(1, 'test_worker'));
        $this->assertEquals(FailingJob::class, $this->cronark->getJob(2, 'test_worker'));
    }

    #[Test]
    #[TestDox('getJob() returns correct job class')]
    public function it_gets_job_by_index(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $job = $this->cronark->getJob(0, 'test_worker');

        $this->assertEquals(TestJob::class, $job);
    }

    #[Test]
    #[TestDox('getJob() returns null for invalid index')]
    public function it_returns_null_for_invalid_index(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->assertNull($this->cronark->getJob(10, 'test_worker'));
    }

    #[Test]
    #[TestDox('hasJob() validates job existence')]
    public function it_validates_job_existence(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->assertTrue($this->cronark->hasJob(0, 'test_worker'));
        $this->assertFalse($this->cronark->hasJob(1, 'test_worker'));
    }

    #[Test]
    #[TestDox('hasAnyJob() checks all workers')]
    public function it_checks_all_workers_for_jobs(): void
    {
        $this->assertFalse($this->cronark->hasAnyJob());

        $this->cronark->addJob(TestJob::class, 'worker1');

        $this->assertTrue($this->cronark->hasAnyJob());
    }

    #[Test]
    #[TestDox('getJobsCount() counts all workers')]
    public function it_counts_all_workers_jobs(): void
    {
        $this->cronark->addJob(TestJob::class, 'worker1');
        $this->cronark->addJob(TestJob::class, 'worker1');
        $this->cronark->addJob(TestJob::class, 'worker2');

        $this->assertEquals(3, $this->cronark->getJobsCount());
        $this->assertEquals(2, $this->cronark->getJobsCount('worker1'));
        $this->assertEquals(1, $this->cronark->getJobsCount('worker2'));
    }

    #[Test]
    #[TestDox('getJobsHash() generates consistent hash')]
    public function it_generates_consistent_hash(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $hash1 = $this->cronark->getJobsHash('test_worker');
        $hash2 = $this->cronark->getJobsHash('test_worker');

        $this->assertEquals($hash1, $hash2);
    }

    #[Test]
    #[TestDox('getJobsHash() changes when jobs change')]
    public function it_changes_hash_when_jobs_change(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $hash1 = $this->cronark->getJobsHash('test_worker');

        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $hash2 = $this->cronark->getJobsHash('test_worker');

        $this->assertNotEquals($hash1, $hash2);
    }

    #[Test]
    #[TestDox('saveJobsHash() and getSavedJobsHash() work together')]
    public function it_saves_and_retrieves_jobs_hash(): void
    {
        $hash = 'test_hash_12345';

        $this->cronark->saveJobsHash($hash, 'test_worker');
        $retrieved = $this->cronark->getSavedJobsHash('test_worker');

        $this->assertEquals($hash, $retrieved);
    }

    #[Test]
    #[TestDox('hasJobsHashChanged() detects changes')]
    public function it_detects_jobs_hash_changes(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        $this->assertFalse($this->cronark->hasJobsHashChanged('test_worker'));

        $this->cronark->addJob(FailingJob::class, 'test_worker');

        $this->assertTrue($this->cronark->hasJobsHashChanged('test_worker'));
    }

    #[Test]
    #[TestDox('setPid() and getPid() work together')]
    public function it_saves_and_retrieves_pid(): void
    {
        $pid = 12345;

        $this->cronark->setPid($pid, 'test_worker');
        $retrieved = $this->cronark->getPid('test_worker');

        $this->assertEquals($pid, $retrieved);
    }

    #[Test]
    #[TestDox('getPid() returns null when not set')]
    public function it_returns_null_for_unset_pid(): void
    {
        $this->assertNull($this->cronark->getPid('test_worker'));
    }

    #[Test]
    #[TestDox('isActive() returns false when no PID saved')]
    public function it_returns_false_when_no_pid(): void
    {
        $this->assertFalse($this->cronark->isActive('test_worker'));
    }

    #[Test]
    #[TestDox('isActive() returns false for non-existent process')]
    public function it_returns_false_for_non_existent_process(): void
    {
        $this->cronark->setPid(999999999, 'test_worker');

        $this->assertFalse($this->cronark->isActive('test_worker'));
    }

    #[Test]
    #[TestDox('isActive() returns true for current process')]
    public function it_returns_true_for_current_process(): void
    {
        $this->cronark->setPid(Process::id(), 'test_worker');

        $this->assertTrue($this->cronark->isActive('test_worker'));
    }

    #[Test]
    #[TestDox('kill() removes PID on success')]
    public function it_removes_pid_on_kill(): void
    {
        $this->cronark->setPid(999999999, 'test_worker');

        $this->cronark->kill('test_worker');

        // PID should be cleared even if kill fails
        $this->assertNull($this->cronark->getPid('test_worker'));
    }

    #[Test]
    #[TestDox('setCurrentJobIndex() and getCurrentJobIndex() work together')]
    public function it_saves_and_retrieves_current_job_index(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        $this->cronark->setCurrentJobIndex(0, 'test_worker');

        $this->assertEquals(0, $this->cronark->getCurrentJobIndex('test_worker'));
    }

    #[Test]
    #[TestDox('getCurrentJobIndex() returns null when hash changed')]
    public function it_returns_null_when_hash_changed(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');
        $this->cronark->setCurrentJobIndex(0, 'test_worker');

        $this->cronark->addJob(FailingJob::class, 'test_worker');

        $this->assertNull($this->cronark->getCurrentJobIndex('test_worker'));
    }

    #[Test]
    #[TestDox('nextJobIndex() increments index')]
    public function it_increments_job_index(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        $this->assertEquals(0, $this->cronark->nextJobIndex('test_worker'));
        $this->assertEquals(1, $this->cronark->nextJobIndex('test_worker'));
    }

    #[Test]
    #[TestDox('nextJobIndex() wraps around')]
    public function it_wraps_around_job_index(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        $this->cronark->nextJobIndex('test_worker'); // 0
        $this->cronark->nextJobIndex('test_worker'); // 1
        $index = $this->cronark->nextJobIndex('test_worker'); // Should wrap to 0

        $this->assertEquals(0, $index);
    }

    #[Test]
    #[TestDox('nextJob() returns correct job class')]
    public function it_returns_next_job_class(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        $this->assertEquals(TestJob::class, $this->cronark->nextJob('test_worker'));
        $this->assertEquals(FailingJob::class, $this->cronark->nextJob('test_worker'));
    }

    #[Test]
    #[TestDox('getCurrentWorker() returns null initially')]
    public function it_returns_null_for_current_worker_initially(): void
    {
        $this->assertNull($this->cronark->getCurrentWorker());
    }

    #[Test]
    #[TestDox('print() outputs in CLI mode')]
    public function it_outputs_in_cli_mode(): void
    {
        if (PHP_SAPI !== 'cli') {
            $this->markTestSkipped('This test only runs in CLI mode');
        }

        ob_start();
        $this->cronark->print('Test message', 'test_worker');
        $output = ob_get_clean();

        $this->assertStringContainsString('Cronark', $output);
        $this->assertStringContainsString('test_worker', $output);
        $this->assertStringContainsString('Test message', $output);
    }
}
