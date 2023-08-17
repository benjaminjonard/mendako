<?php

require_once \dirname(__DIR__).'/vendor/autoload.php';

use App\Service\ThumbnailGenerator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__.'/../.env.local');

//@TODO Allow only some width
$width = $_REQUEST['width'];
$imagePath = $_REQUEST['path'];
$fullImagePath = __DIR__."/{$imagePath}";

if (!\file_exists($fullImagePath)) {
    return new BinaryFileResponse('build/images/default.png');
}

$info = \pathinfo($imagePath);
$extension = str_replace('image/', '', mime_content_type($imagePath));

if ($extension === 'svg') {
    \header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
    \header("Cache-Control: immutable, max-age=31536000, no-transform, private, s-maxage=31536000");
    \header("Content-Type: " . \mime_content_type($imagePath));
    \header("Content-Transfer-Encoding: Binary");
    \header("Content-Length:" . \filesize($imagePath));
    \header("Content-Disposition: attachment; filename=" . $info['filename'] . '.' . $info['extension']);
    \readfile($imagePath);
    die();
}

$thumbnailsFormat = match ($_ENV['APP_THUMBNAILS_FORMAT']) {
    'jpeg' => 'jpeg',
    'png' => 'png',
    'webp' => 'webp',
    'avif' => 'avif',
    default => $extension
};

$dirname = str_replace('uploads', 'thumbnails', $info['dirname']);
$filename = $info['filename'];
$fullThumbnailPath = __DIR__ . "/{$dirname}/{$filename}_{$width}.{$thumbnailsFormat}";

if (!\file_exists($fullThumbnailPath)) {
    try {
        $thumbnailGenerator = new ThumbnailGenerator();
        $thumbnailGenerator->generate($fullImagePath, $fullThumbnailPath, $width, $thumbnailsFormat);
    } catch (Throwable $throwable) {
        var_dump($throwable->getMessage());
        die();  // IMPORTANT: in case of error the response will be base64 encoded
    }
}

\header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
\header("Cache-Control: immutable, max-age=31536000, no-transform, private, s-maxage=31536000");
\header("Content-Type: " . \mime_content_type($fullThumbnailPath));
\header("Content-Transfer-Encoding: Binary");
\header("Content-Length:" . \filesize($fullThumbnailPath));
\header("Content-Disposition: attachment; filename=" . $info['filename'] . '_' . $width . '.' . $info['extension']);
\readfile($fullThumbnailPath);
die();
