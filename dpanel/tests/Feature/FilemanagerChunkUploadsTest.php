<?php

namespace Tests\Feature;

use App\Services\Filemanager\FilemanagerChunkUploads;
use Tests\TestCase;

class FilemanagerChunkUploadsTest extends TestCase
{
    private FilemanagerChunkUploads $uploads;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/fm-chunk-test-'.uniqid();
        $this->uploads = new class($this->root) extends FilemanagerChunkUploads {
            public function __construct(private readonly string $base)
            {
            }

            public function root(): string
            {
                return $this->base;
            }
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_out_of_order_chunks_are_assembled_in_place(): void
    {
        $chunk = FilemanagerChunkUploads::CHUNK_BYTES;
        $content = random_bytes(2 * $chunk + 123);
        $target = ['path' => 'public_html', 'root' => '', 'filename' => 'big.bin'];
        $id = $this->uploads->init('site-1', 7, strlen($content), $target);

        foreach ([2, 0, 1, 1] as $index) {
            $this->storeChunk($id, $index, substr($content, $index * $chunk, $chunk));
        }

        $staged = $this->uploads->complete($id, 'site-1', 7);
        $this->assertSame($target, $staged['target']);
        $this->assertSame(hash('sha256', $content), hash_file('sha256', $staged['path']));

        $this->uploads->delete($id);
        $this->assertDirectoryDoesNotExist($this->root.'/'.$id);
    }

    public function test_complete_fails_when_a_chunk_is_missing(): void
    {
        $chunk = FilemanagerChunkUploads::CHUNK_BYTES;
        $content = random_bytes($chunk + 10);
        $id = $this->uploads->init('site-1', 7, strlen($content), ['path' => '', 'root' => '', 'filename' => 'a.bin']);
        $this->storeChunk($id, 1, substr($content, $chunk));

        $this->expectExceptionMessage('Missing upload chunk 0.');
        $this->uploads->complete($id, 'site-1', 7);
    }

    public function test_chunk_with_wrong_size_or_index_is_rejected(): void
    {
        $id = $this->uploads->init('site-1', 7, 100, ['path' => '', 'root' => '', 'filename' => 'a.bin']);

        try {
            $this->storeChunk($id, 0, str_repeat('x', 99));
            $this->fail('A short chunk was accepted.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->storeChunk($id, 1, str_repeat('x', 100));
    }

    public function test_empty_file_completes_with_one_empty_chunk(): void
    {
        $id = $this->uploads->init('site-1', 7, 0, ['path' => '', 'root' => '', 'filename' => 'empty.txt']);
        $this->storeChunk($id, 0, '');

        $this->assertSame(0, filesize($this->uploads->complete($id, 'site-1', 7)['path']));
    }

    public function test_upload_is_bound_to_its_website_and_user(): void
    {
        $id = $this->uploads->init('site-1', 7, 3, ['path' => '', 'root' => '', 'filename' => 'a.txt']);

        $this->expectExceptionMessage('Upload not found.');
        $this->storeChunk($id, 0, 'abc', 'site-2', 7);
    }

    private function storeChunk(string $id, int $index, string $bytes, string $websiteId = 'site-1', int $userId = 7): void
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($path, $bytes);
        try {
            $this->uploads->storeChunk($id, $websiteId, $userId, $index, $path);
        } finally {
            unlink($path);
        }
    }
}
