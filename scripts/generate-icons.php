<?php
/** Generate JA Tuckshop PNG icons if GD is available. Run: php scripts/generate-icons.php */

$dir = dirname(__DIR__) . '/assets/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD extension not available - SVG icon only.\n");
    exit(0);
}

foreach ([192, 512] as $size) {
    $img = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($img, 0, 0, 0);
    $red = imagecolorallocate($img, 255, 26, 26);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);

    $text = 'JA';
    $font = 5;
    $charWidth = imagefontwidth($font);
    $charHeight = imagefontheight($font);
    $scale = max(8, (int) floor($size / 24));
    $textWidth = $charWidth * strlen($text) * $scale;
    $textHeight = $charHeight * $scale;
    $x = (int) (($size - $textWidth) / 2);
    $y = (int) (($size - $textHeight) / 2);

    for ($dx = 0; $dx < $scale; $dx++) {
        for ($dy = 0; $dy < $scale; $dy++) {
            imagestring($img, $font, $x + $dx, $y + $dy, $text, $red);
        }
    }

    $path = $dir . "/icon-{$size}.png";
    imagepng($img, $path);
    imagedestroy($img);
    echo "Created $path\n";
}
