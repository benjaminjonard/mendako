<?php

use Symfony\Component\HttpFoundation\BinaryFileResponse;

//@TODO Allow only some width
$width = $_REQUEST['width'];
$imagePath = $_REQUEST['path'];

$publicPath = getcwd();
$fullImagePath = "$publicPath/$imagePath";

if (!file_exists($fullImagePath)) {
    return new BinaryFileResponse('build/images/default.png');
}

$info = pathinfo($imagePath);
$thumbnailPath = $info['dirname'] . '/thumbnails/' . $info['filename'] . '_' . $width . '.' . $info['extension'];
$fullThumbnailPath = $publicPath . '/' . $thumbnailPath;

if (!file_exists($fullThumbnailPath)) {
    try {
        generate($fullImagePath, $fullThumbnailPath, $width);
    } catch (Throwable $throwable) {
        echo $throwable->getMessage();
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

function generate(string $path, string $thumbnailPath, int $thumbnailWidth)
{
    if (!is_file($path)) {
        return false;
    }

    list($width, $height, $mime) = getimagesize($path);
    if ($width <= $thumbnailWidth) {
        $thumbnailWidth = $width;
    }
    $thumbnailHeight = (int)floor($height * ($thumbnailWidth / $width));

    // Create user directory in uploads
    $dir = explode('/', $thumbnailPath);
    array_pop($dir);
    $dir = implode('/', $dir);

    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new \Exception('There was a problem while uploading the image. Please try again!');
    }

    $image = match ($mime) {
        IMAGETYPE_JPEG, IMAGETYPE_JPEG2000 => imagecreatefromjpeg($path),
        IMAGETYPE_PNG => imagecreatefrompng($path),
        IMAGETYPE_WEBP => imagecreatefromwebp($path),
        default => throw new \Exception('Your image cannot be processed, please use another one.'),
    };

    $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

    // Transparency
    if (IMAGETYPE_PNG === $mime || IMAGETYPE_WEBP === $mime) {
        imagecolortransparent($thumbnail, imagecolorallocate($thumbnail, 0, 0, 0));
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
    }

    imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, $width, $height);
    $deg = guessRotation($path);
    $thumbnail = imagerotate($thumbnail, $deg, 0);

    switch ($mime) {
        case IMAGETYPE_JPEG:
        case IMAGETYPE_JPEG2000:
            imagejpeg($thumbnail, $thumbnailPath, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($thumbnail, $thumbnailPath, 9);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($thumbnail, $thumbnailPath, 85);
            break;
        default:
            break;
    }


    return true;
}

function guessRotation(string $path): int
{
    $deg = 0;

    if (\function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        if ($exif && isset($exif['Orientation'])) {
            $orientation = $exif['Orientation'];
            if (1 != $orientation) {
                switch ($orientation) {
                    case 3:
                        $deg = 180;
                        break;
                    case 6:
                        $deg = 270;
                        break;
                    case 8:
                        $deg = 90;
                        break;
                }
            }
        }
    }

    return $deg;
}