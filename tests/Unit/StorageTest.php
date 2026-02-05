<?php namespace Nabeghe\Cronark\Tests\Unit;

use Nabeghe\Cronark\Storage;
use Nabeghe\Cronark\StorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Storage::class)]
class StorageTest extends TestCase
{
    private Storage $storage;
    private string $testStoragePath;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cronark_test_'.uniqid();
        $this->storage = new Storage($this->testStoragePath);
    }

    protected function tearDown(): void
    {
        // Clean up test storage files
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
    #[TestDox('Storage implements StorageInterface')]
    public function it_implements_storage_interface(): void
    {
        $this->assertInstanceOf(StorageInterface::class, $this->storage);
    }

    #[Test]
    #[TestDox('Storage creates directory if not exists')]
    public function it_creates_storage_directory(): void
    {
        $this->assertDirectoryExists($this->testStoragePath);
    }

    #[Test]
    #[TestDox('Storage::get() returns null for non-existent key')]
    public function it_returns_null_for_non_existent_key(): void
    {
        $result = $this->storage->get('non_existent_key');

        $this->assertNull($result);
    }

    #[Test]
    #[TestDox('Storage::set() and get() work correctly')]
    public function it_stores_and_retrieves_values(): void
    {
        $this->assertTrue($this->storage->set('test_key', 'test_value'));
        $this->assertEquals('test_value', $this->storage->get('test_key'));
    }

    #[Test]
    #[TestDox('Storage handles different data types')]
    #[DataProvider('provideDataTypes')]
    public function it_handles_different_data_types(mixed $value): void
    {
        $this->storage->set('key', $value);
        $retrieved = $this->storage->get('key');

        $this->assertEquals($value, $retrieved);
    }

    public static function provideDataTypes(): array
    {
        return [
            'string' => ['test string'],
            'integer' => [42],
            'float' => [3.14],
            'boolean true' => [true],
            'boolean false' => [false],
            'array' => [['a', 'b', 'c']],
            'associative array' => [['key' => 'value']],
            'null' => [null],
        ];
    }

    #[Test]
    #[TestDox('Storage isolates workers')]
    public function it_isolates_workers(): void
    {
        $this->storage->set('key', 'worker1_value', 'worker1');
        $this->storage->set('key', 'worker2_value', 'worker2');

        $this->assertEquals('worker1_value', $this->storage->get('key', 'worker1'));
        $this->assertEquals('worker2_value', $this->storage->get('key', 'worker2'));
    }

    #[Test]
    #[TestDox('Storage::set() overwrites existing values')]
    public function it_overwrites_existing_values(): void
    {
        $this->storage->set('key', 'old_value');
        $this->storage->set('key', 'new_value');

        $this->assertEquals('new_value', $this->storage->get('key'));
    }

    #[Test]
    #[TestDox('Storage::set() with null removes key')]
    public function it_removes_key_when_setting_null(): void
    {
        $this->storage->set('key', 'value');
        $this->assertEquals('value', $this->storage->get('key'));

        $this->storage->set('key', null);
        $this->assertNull($this->storage->get('key'));
    }

    #[Test]
    #[TestDox('Storage::clear() removes worker storage')]
    public function it_clears_worker_storage(): void
    {
        $this->storage->set('key1', 'value1', 'worker1');
        $this->storage->set('key2', 'value2', 'worker1');

        $this->assertTrue($this->storage->clear('worker1'));

        $this->assertNull($this->storage->get('key1', 'worker1'));
        $this->assertNull($this->storage->get('key2', 'worker1'));
    }

    #[Test]
    #[TestDox('Storage persists data across instances')]
    public function it_persists_data_across_instances(): void
    {
        $this->storage->set('key', 'persistent_value');

        $newStorage = new Storage($this->testStoragePath);

        $this->assertEquals('persistent_value', $newStorage->get('key'));
    }

    #[Test]
    #[TestDox('Storage handles concurrent writes')]
    public function it_handles_concurrent_writes(): void
    {
        $this->storage->set('key', 'value1');
        $this->storage->set('key', 'value2');
        $this->storage->set('key', 'value3');

        $this->assertEquals('value3', $this->storage->get('key'));
    }
}
