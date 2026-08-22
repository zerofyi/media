<?php

declare(strict_types=1);

namespace Zerofyi\Media\Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Helpers for constructing realistic UploadedFile instances in tests.
 * All image data is generated on-the-fly using GD; no fixture files needed.
 */
trait MakesUploadedFiles
{
    /**
     * Create a real 100×100 JPEG UploadedFile stored in a temp file.
     */
    protected function makeJpeg(string $name = 'test.jpg', int $sizeKb = 10): UploadedFile
    {
        return $this->makeRasterFile($name, 'image/jpeg', $sizeKb);
    }

    /**
     * Create a real 100×100 PNG UploadedFile.
     */
    protected function makePng(string $name = 'test.png', int $sizeKb = 10): UploadedFile
    {
        return $this->makeRasterFile($name, 'image/png', $sizeKb);
    }

    /**
     * Create a minimal, valid SVG UploadedFile.
     */
    protected function makeSvg(string $name = 'test.svg', string $content = ''): UploadedFile
    {
        $svg = $content ?: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="blue"/></svg>';

        $tmp = tempnam(sys_get_temp_dir(), 'svg_');
        file_put_contents($tmp, $svg);

        return new UploadedFile($tmp, $name, 'image/svg+xml', null, true);
    }

    /**
     * Create an UploadedFile whose declared MIME is image/jpeg but whose
     * bytes are actually PNG — used to test magic-byte rejection.
     */
    protected function makeMismatchedFile(string $name = 'evil.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mismatch_');
        // Write a real PNG header but give it a .jpg name / jpeg MIME.
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $tmp);
        imagedestroy($image);

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    /**
     * Create a text file disguised as a JPEG — for MIME validation tests.
     */
    protected function makeFakeJpeg(string $name = 'not-an-image.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fake_');
        file_put_contents($tmp, 'this is not image data');

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    /**
     * Create a file with a double-extension filename.
     */
    protected function makeDoubleExtension(string $name = 'shell.php.jpg'): UploadedFile
    {
        return $this->makeRasterFile($name, 'image/jpeg');
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function makeRasterFile(string $name, string $mime, int $sizeKb = 10): UploadedFile
    {
        $tmp   = tempnam(sys_get_temp_dir(), 'img_');
        $image = imagecreatetruecolor(100, 100);
        $color = imagecolorallocate($image, 100, 149, 237);
        imagefill($image, 0, 0, $color);

        match ($mime) {
            'image/png'  => imagepng($image, $tmp),
            default      => imagejpeg($image, $tmp, 85),
        };

        imagedestroy($image);

        return new UploadedFile($tmp, $name, $mime, null, true);
    }
}