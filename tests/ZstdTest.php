<?php
declare(strict_types=1);
namespace codename\parquet\tests;

use codename\parquet\ParquetReader;
use codename\parquet\StreamHelper;
use codename\parquet\ZstdStreamWrapper;

final class ZstdTest extends TestBase
{
  /**
   * @inheritDoc
   * @requires extension zstd
   */
  protected function setUp(): void
  {
    parent::setUp();
  }

  /**
   * Simple test for a working zstd extension
   */
  public function testSimpleZstd(): void {
    $sampleString = 'sample';
    $compressed = zstd_compress($sampleString);
    $uncompressed = zstd_uncompress($compressed);

    $this->assertNotFalse($compressed); // Assert it's not FALSE (-> erroneous)
    $this->assertEquals($sampleString, $uncompressed);
  }

  /**
   * [testCompressDecompressRandomByteChunks description]
   */
  public function testCompressDecompressRandomByteChunks(): void {
    for ($i=0; $i < 100; $i++) {
      $this->Compress_decompress_random_byte_chunks($i);
    }
  }

  /**
   * [Compress_decompress_random_byte_chunks description]
   * @param int $index [description]
   */
  public function Compress_decompress_random_byte_chunks(int $index): void
  {
    // $stage1 = RandomGenerator.GetRandomBytes(2, 1000);
    // $stage1 = "A small string to compress esses a a press a mall tring \n"; // (string)random_bytes(1000);
    $stage1 = random_bytes(1000);
    $stage2 = null; // byte[] stage2;
    $stage3 = null; // byte[] stage3;

    $source = fopen('php://memory', 'r+');

    ZstdStreamWrapper::register();
    $zstd = ZstdStreamWrapper::createWrappedStream($source, 'r+', ZstdStreamWrapper::MODE_COMPRESS);

    fwrite($zstd, $stage1);
    StreamHelper::MarkWriteFinished($zstd);

    // fseek($source, 0); // ?
    $stage2 = stream_get_contents($source, -1, 0);

    $source = fopen('php://memory', 'r+');
    fwrite($source, $stage2);
    fseek($source, 0);

    $zstd = ZstdStreamWrapper::createWrappedStream($source, 'r+', ZstdStreamWrapper::MODE_DECOMPRESS);

    $ms = fopen('php://memory', 'r+');

    stream_copy_to_stream($zstd, $ms);

    $stage3 = stream_get_contents($ms, -1, 0);

    // validate
    $this->assertEquals($stage1, $stage3);
  }

  /**
   * Fixtures written by other Parquet implementations (see tests/data/zstd/generate.sh)
   * @return array
   */
  public static function externalZstdFileProvider(): array
  {
    return [
      'duckdb'                => [ 'zstd/duckdb.zstd.parquet' ],
      'pyarrow'               => [ 'zstd/pyarrow.zstd.parquet' ],
      'pyarrow data page v2'  => [ 'zstd/pyarrow.datapagev2.zstd.parquet' ],
    ];
  }

  /**
   * Reads zstd files produced by external writers and checks every value
   * @dataProvider externalZstdFileProvider
   * @param string $file
   */
  public function testReadsZstdFileFromExternalWriter(string $file): void
  {
    $reader = new ParquetReader($this->openTestFile($file));

    $this->assertGreaterThan(1, $reader->getRowGroupCount());

    $data = [];
    for ($g = 0; $g < $reader->getRowGroupCount(); $g++) {
      foreach ($reader->ReadEntireRowGroup($g) as $column) {
        $name = $column->getField()->name;
        $data[$name] = array_merge($data[$name] ?? [], $column->getData());
      }
    }

    $n = 5000;
    $expected = [
      'id'       => range(0, $n - 1),
      'half'     => array_map(fn($i) => $i * 0.5, range(0, $n - 1)),
      'category' => array_map(fn($i) => 'cat_' . ($i % 10), range(0, $n - 1)),
      'maybe'    => array_map(fn($i) => $i % 7 === 0 ? null : $i, range(0, $n - 1)),
    ];

    $this->assertSame(array_keys($expected), array_keys($data));
    foreach ($expected as $name => $values) {
      $this->assertSame($values, $data[$name], "column '{$name}' in {$file}");
    }
  }
}
