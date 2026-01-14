<?php

declare(strict_types=1);

namespace Genkgo\TestArchiveStream\Integration;

use Genkgo\ArchiveStream\Archive;
use Genkgo\ArchiveStream\CallbackContents;
use Genkgo\ArchiveStream\EmptyDirectory;
use Genkgo\ArchiveStream\ResourceContent;
use Genkgo\ArchiveStream\StringContent;
use Genkgo\ArchiveStream\ZipReader;
use Genkgo\TestArchiveStream\AbstractTestCase;

final class ZipStreamTest extends AbstractTestCase
{
    public function testCreateFileZip()
    {
        $archive = (new Archive())->withContent(new StringContent('test.txt', 'content'));

        $filename = \tempnam(\sys_get_temp_dir(), 'zip');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        $zip = new \ZipArchive();
        $result = $zip->open($filename);

        $this->assertTrue($result);
        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('test.txt', $zip->getNameIndex(0));
        $this->assertEquals('content', $zip->getFromIndex(0));
    }

    public function testCreateEmptyDirectory()
    {
        $archive = (new Archive())->withContent(new EmptyDirectory('empty'));

        $filename = \tempnam(\sys_get_temp_dir(), 'zip');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        $zip = new \ZipArchive();
        $result = $zip->open($filename);

        $this->assertTrue($result);
        $this->assertEquals(1, $zip->numFiles);
        $this->assertEquals('empty/', $zip->getNameIndex(0));
    }

    public function testCreateFileEmptyDirectory()
    {
        $archive = (new Archive())
            ->withContent(new EmptyDirectory('directory'))
            ->withContent(new StringContent('other/file.txt', 'data'));

        $filename = \tempnam(\sys_get_temp_dir(), 'zip');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        $zip = new \ZipArchive();
        $result = $zip->open($filename);

        $this->assertTrue($result);
        $this->assertEquals(2, $zip->numFiles);
        $this->assertEquals('directory/', $zip->getNameIndex(0));
        $this->assertEquals('other/file.txt', $zip->getNameIndex(1));
        $this->assertEquals('data', $zip->getFromIndex(1));
    }

    public function testWithContents()
    {
        $archive = (new Archive())
            ->withContents([new EmptyDirectory('directory'), new StringContent('other/file.txt', 'data')]);

        $filename = \tempnam(\sys_get_temp_dir(), 'zip');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        $zip = new \ZipArchive();
        $result = $zip->open($filename);

        $this->assertTrue($result);
        $this->assertEquals(2, $zip->numFiles);
        $this->assertEquals('directory/', $zip->getNameIndex(0));
        $this->assertEquals('other/file.txt', $zip->getNameIndex(1));
        $this->assertEquals('data', $zip->getFromIndex(1));
    }

    public function testWithCallbackIterator()
    {
        $archive = (new Archive())
            ->withContents(
                new CallbackContents(
                    function () {
                        return new \ArrayIterator([
                            new EmptyDirectory('directory'),
                            new StringContent('other/file.txt', 'data')
                        ]);
                    }
                )
            );

        $filename = \tempnam(\sys_get_temp_dir(), 'zip');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        $zip = new \ZipArchive();
        $result = $zip->open($filename);

        $this->assertTrue($result);
        $this->assertEquals(2, $zip->numFiles);
        $this->assertEquals('directory/', $zip->getNameIndex(0));
        $this->assertEquals('other/file.txt', $zip->getNameIndex(1));
        $this->assertEquals('data', $zip->getFromIndex(1));
    }

    public function testFourGigabyte()
    {
        if (\getenv('RUN_4GB_TEST') !== '1') {
            $this->markTestSkipped('4GB test disabled by environment variable.');
        }

        \exec(
            'rm -Rf /tmp/4gb_test*',
            $output,
            $exitCode
        );

        $archive = (new Archive())
            ->withContents(
                new CallbackContents(
                    function () {
                        for ($i = 0, $j = 4400; $i < $j; $i++) {
                            $resource = $this->createRandomFile(1_000_000);
                            yield new ResourceContent('4gb_test_' . $i . '.bin', $resource);
                        }
                    }
                )
            );

        $filename = \tempnam(\sys_get_temp_dir(), '4gb_test');
        $fileStream = \fopen($filename, 'r+');

        $zipStream = new ZipReader($archive);
        $generator = $zipStream->read(1048576);
        foreach ($generator as $stream) {
            while ($stream->eof() === false) {
                $data = $stream->fread(9999);
                \fwrite($fileStream, $data);
            }
        }
        \fclose($fileStream);

        \exec(
            'unzip -t ' . \escapeshellarg($filename) . ' 2>&1',
            $output,
            $exitCode
        );

        $this->assertSame(
            0,
            $exitCode,
            "ZIP failed unzip validation:\n" . \implode("\n", $output)
        );
    }

    private function createRandomFile(int $size)
    {
        $temp = \fopen('php://temp', 'r+');
        $chunk = 4 * 1024 * 1024;
        while ($size > 0) {
            $writeSize = \min($chunk, $size);
            $data = \random_bytes($writeSize);
            \fwrite($temp, $data);
            $size -= $writeSize;
        }

        \rewind($temp);
        return $temp;
    }
}
