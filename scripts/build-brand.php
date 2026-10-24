<?php

// Derive web sizes from the generated transparent master, without booting the
// application or changing the source image. Run: php scripts/build-brand.php
$root = dirname(__DIR__);
$source = imagecreatefrompng($root.'/resources/branding/certimate-mark-master.png');
if (! $source) {
    throw new RuntimeException('The Certimate logo master could not be read.');
}

$resize = static function (int $size, bool $iconBackground = false) use ($source): GdImage {
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $background = $iconBackground ? imagecolorallocate($image, 246, 247, 248) : imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $background);
    imagealphablending($image, $iconBackground);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

    return $image;
};

$mark = $resize(256);
imagepng($mark, $root.'/public/brand/certimate-mark.png', 9);
imagewebp($mark, $root.'/public/brand/certimate-mark.webp', IMG_WEBP_LOSSLESS);
imagedestroy($mark);

foreach ([16 => 'favicon-16x16.png', 32 => 'favicon-32x32.png', 180 => 'apple-touch-icon.png',
    192 => 'android-chrome-192x192.png', 512 => 'android-chrome-512x512.png'] as $size => $filename) {
    $image = $resize($size, true);
    imagepng($image, $root.'/public/'.$filename, 9);
    imagedestroy($image);
}

$frames = [];
foreach ([16, 32, 48, 64] as $size) {
    $image = $resize($size, true);
    ob_start();
    imagepng($image, null, 9);
    $frames[] = ['size' => $size, 'data' => ob_get_clean()];
    imagedestroy($image);
}
$icon = pack('vvv', 0, 1, count($frames));
$offset = 6 + 16 * count($frames);
foreach ($frames as $frame) {
    $length = strlen($frame['data']);
    $icon .= pack('CCCCvvVV', $frame['size'], $frame['size'], 0, 0, 1, 32, $length, $offset);
    $offset += $length;
}
foreach ($frames as $frame) {
    $icon .= $frame['data'];
}
file_put_contents($root.'/public/favicon.ico', $icon);
imagedestroy($source);
echo "Certimate web logo and browser icons generated from the preserved master.\n";
