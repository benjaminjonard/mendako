<?php

require_once dirname(__DIR__).'/vendor/autoload.php';

use App\Service\ThumbnailGenerator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

//@TODO Allow only some width
$width = $_REQUEST['width'];
$imagePath = $_REQUEST['path'];
$publicPath = getcwd();
$fullImagePath = "{$publicPath}/{$imagePath}";

if (!file_exists($fullImagePath)) {
    return new BinaryFileResponse('build/images/default.png');
}

$info = pathinfo($imagePath);
$thumbnailPath = $info['dirname'] . '/thumbnails/' . $info['filename'] . '_' . $width . '.' . $info['extension'];
$fullThumbnailPath = $publicPath . '/' . $thumbnailPath;
$thumbnailGenerator = new ThumbnailGenerator();

if (!file_exists($fullThumbnailPath)) {
    try {
        $thumbnailGenerator->generate($fullImagePath, $fullThumbnailPath, $width);
    } catch (Throwable $throwable) {
        echo $throwable->getMessage();
        die();  // IMPORTANT: in case error the response will be base64 encoded
    }
}

header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
header("Cache-Control: immutable, max-age=31536000, no-transform, private, s-maxage=31536000");
header("Content-Type: " . mime_content_type($fullThumbnailPath));
header("Content-Transfer-Encoding: Binary");
header("Content-Length:" . filesize($fullThumbnailPath));
header("Content-Disposition: attachment; filename=" . $info['filename'] . '_' . $width . '.' . $info['extension']);
readfile($fullThumbnailPath);
die();
