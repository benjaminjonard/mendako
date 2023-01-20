<?php

declare(strict_types=1);

namespace App\Service;

class GifDataExtractor
{
    public $gifWidth;
    public $gifHeight;
    private array $frames;

    private array $frameDurations;

    private array $frameImages;

    private array $framePositions;

    private array $frameDimensions;

    private int $frameNumber;

    private array $frameSources;

    private array $fileHeader;

    private int $pointer;

    private int $gifMaxWidth;

    private int $gifMaxHeight;

    private int $totalDuration;

    private $handle;

    private array $globaldata;

    private array $orgvars;

    public function extract(string $filename, bool $originalFrames = false): array
    {
        if (!self::isAnimatedGif($filename)) {
            throw new \Exception('The GIF image you are trying to explode is not animated !');
        }

        $this->reset();
        $this->parseFramesInfo($filename);
        $prevImg = null;
        $frameSourcesCount = count($this->frameSources);

        for ($i = 0; $i < $frameSourcesCount; ++$i) {

            $this->frames[$i] = array();
            $this->frameDurations[$i] = $this->frameSources[$i]['delay_time'];
            $this->frames[$i]['duration'] = $this->frameSources[$i]['delay_time'];

            $img = imagecreatefromstring($this->fileHeader["gifheader"] . $this->frameSources[$i]["graphicsextension"] . $this->frameSources[$i]["imagedata"] . chr(0x3b));

            if (!$originalFrames) {

                $prevImg = $i > 0 ? $this->frames[$i - 1]['image'] : $img;

                $sprite = imagecreate($this->gifMaxWidth, $this->gifMaxHeight);
                imagesavealpha($sprite, true);

                $transparent = imagecolortransparent($prevImg);

                if ($transparent > -1 && imagecolorstotal($prevImg) > $transparent) {

                    $actualTrans = imagecolorsforindex($prevImg, $transparent);
                    imagecolortransparent($sprite, imagecolorallocate($sprite, $actualTrans['red'], $actualTrans['green'], $actualTrans['blue']));
                }

                if ((int)$this->frameSources[$i]['disposal_method'] == 1 && $i > 0) {

                    imagecopy($sprite, $prevImg, 0, 0, 0, 0, $this->gifMaxWidth, $this->gifMaxHeight);
                }

                imagecopyresampled($sprite, $img, $this->frameSources[$i]["offset_left"], $this->frameSources[$i]["offset_top"], 0, 0, $this->gifMaxWidth, $this->gifMaxHeight, $this->gifMaxWidth, $this->gifMaxHeight);
                $img = $sprite;
            }
            $this->frameImages[$i] = $img;
            $this->frames[$i]['image'] = $img;
        }

        return [
            'duration' => $this->totalDuration / 100,
            'width' => $this->gifMaxWidth,
            'height' => $this->gifMaxHeight
        ];
    }

    public static function isAnimatedGif(string $filename): bool
    {
        if (!($fh = @fopen($filename, 'rb'))) {
            return false;
        }

        $count = 0;

        while (!feof($fh) && $count < 2) {

            $chunk = fread($fh, 1024 * 100); //read 100kb at a time
            $count += preg_match_all('/\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)/s', $chunk, $matches);
        }

        fclose($fh);
        return $count > 1;
    }

    private function parseFramesInfo(string $filename): void
    {
        $this->openFile($filename);
        $this->parseGifHeader();
        $this->parseGraphicsExtension(0);
        $this->getApplicationData();
        $this->getApplicationData();
        $this->getFrameString(0);
        $this->parseGraphicsExtension(1);
        $this->getCommentData();
        $this->getApplicationData();
        $this->getFrameString(1);

        while (!$this->checkByte(0x3b) && !$this->checkEOF()) {

            $this->getCommentData();
            $this->parseGraphicsExtension(2);
            $this->getFrameString(2);
            $this->getApplicationData();
        }
    }

    private function parseGifHeader(): void
    {
        $this->pointerForward(10);

        if ($this->readBits(($mybyte = $this->readByteInt()), 0, 1) == 1) {

            $this->pointerForward(2);
            $this->pointerForward(pow(2, $this->readBits($mybyte, 5, 3) + 1) * 3);

        } else {

            $this->pointerForward(2);
        }

        $this->fileHeader["gifheader"] = $this->dataPart(0, $this->pointer);

        // Decoding
        $this->orgvars["gifheader"] = $this->fileHeader["gifheader"];
        $this->orgvars["background_color"] = $this->orgvars["gifheader"][11];
    }

    private function getApplicationData(): void
    {
        $startdata = $this->readByte(2);

        if ($startdata == chr(0x21) . chr(0xff)) {

            $start = $this->pointer - 2;
            $this->pointerForward($this->readByteInt());
            $this->readDataStream($this->readByteInt());
            $this->fileHeader["applicationdata"] = $this->dataPart($start, $this->pointer - $start);

        } else {

            $this->pointerRewind(2);
        }
    }

    private function getCommentData(): void
    {
        $startdata = $this->readByte(2);

        if ($startdata == chr(0x21) . chr(0xfe)) {

            $start = $this->pointer - 2;
            $this->readDataStream($this->readByteInt());
            $this->fileHeader["commentdata"] = $this->dataPart($start, $this->pointer - $start);

        } else {

            $this->pointerRewind(2);
        }
    }

    private function parseGraphicsExtension(int $type): void
    {
        $startdata = $this->readByte(2);

        if ($startdata == chr(0x21) . chr(0xf9)) {

            $start = $this->pointer - 2;
            $this->pointerForward($this->readByteInt());
            $this->pointerForward(1);

            if ($type == 2) {

                $this->frameSources[$this->frameNumber]["graphicsextension"] = $this->dataPart($start, $this->pointer - $start);

            } elseif ($type == 1) {

                $this->orgvars["hasgx_type_1"] = 1;
                $this->globaldata["graphicsextension"] = $this->dataPart($start, $this->pointer - $start);

            } elseif ($type == 0) {

                $this->orgvars["hasgx_type_0"] = 1;
                $this->globaldata["graphicsextension_0"] = $this->dataPart($start, $this->pointer - $start);
            }

        } else {

            $this->pointerRewind(2);
        }
    }

    private function getFrameString(int $type): void
    {
        if ($this->checkByte(0x2c)) {

            $start = $this->pointer;
            $this->pointerForward(9);

            if ($this->readBits(($mybyte = $this->readByteInt()), 0, 1) == 1) {

                $this->pointerForward(pow(2, $this->readBits($mybyte, 5, 3) + 1) * 3);
            }

            $this->pointerForward(1);
            $this->readDataStream($this->readByteInt());
            $this->frameSources[$this->frameNumber]["imagedata"] = $this->dataPart($start, $this->pointer - $start);

            if ($type == 0) {

                $this->orgvars["hasgx_type_0"] = 0;

                if (isset($this->globaldata["graphicsextension_0"])) {

                    $this->frameSources[$this->frameNumber]["graphicsextension"] = $this->globaldata["graphicsextension_0"];

                } else {

                    $this->frameSources[$this->frameNumber]["graphicsextension"] = null;
                }

                unset($this->globaldata["graphicsextension_0"]);

            } elseif ($type == 1) {

                if (isset($this->orgvars["hasgx_type_1"]) && $this->orgvars["hasgx_type_1"] == 1) {

                    $this->orgvars["hasgx_type_1"] = 0;
                    $this->frameSources[$this->frameNumber]["graphicsextension"] = $this->globaldata["graphicsextension"];
                    unset($this->globaldata["graphicsextension"]);

                } else {

                    $this->orgvars["hasgx_type_0"] = 0;
                    $this->frameSources[$this->frameNumber]["graphicsextension"] = $this->globaldata["graphicsextension_0"];
                    unset($this->globaldata["graphicsextension_0"]);
                }
            }

            $this->parseFrameData();
            ++$this->frameNumber;
        }
    }

    private function parseFrameData(): void
    {
        $this->frameSources[$this->frameNumber]["disposal_method"] = $this->getImageDataBit("ext", 3, 3, 3);
        $this->frameSources[$this->frameNumber]["user_input_flag"] = $this->getImageDataBit("ext", 3, 6, 1);
        $this->frameSources[$this->frameNumber]["transparent_color_flag"] = $this->getImageDataBit("ext", 3, 7, 1);
        $this->frameSources[$this->frameNumber]["delay_time"] = $this->dualByteVal($this->getImageDataByte("ext", 4, 2));
        $this->totalDuration += $this->frameSources[$this->frameNumber]["delay_time"];
        $this->frameSources[$this->frameNumber]["transparent_color_index"] = ord($this->getImageDataByte("ext", 6, 1));
        $this->frameSources[$this->frameNumber]["offset_left"] = $this->dualByteVal($this->getImageDataByte("dat", 1, 2));
        $this->frameSources[$this->frameNumber]["offset_top"] = $this->dualByteVal($this->getImageDataByte("dat", 3, 2));
        $this->frameSources[$this->frameNumber]["width"] = $this->dualByteVal($this->getImageDataByte("dat", 5, 2));
        $this->frameSources[$this->frameNumber]["height"] = $this->dualByteVal($this->getImageDataByte("dat", 7, 2));
        $this->frameSources[$this->frameNumber]["local_color_table_flag"] = $this->getImageDataBit("dat", 9, 0, 1);
        $this->frameSources[$this->frameNumber]["interlace_flag"] = $this->getImageDataBit("dat", 9, 1, 1);
        $this->frameSources[$this->frameNumber]["sort_flag"] = $this->getImageDataBit("dat", 9, 2, 1);
        $this->frameSources[$this->frameNumber]["color_table_size"] = pow(2, $this->getImageDataBit("dat", 9, 5, 3) + 1) * 3;
        $this->frameSources[$this->frameNumber]["color_table"] = substr($this->frameSources[$this->frameNumber]["imagedata"], 10, $this->frameSources[$this->frameNumber]["color_table_size"]);
        $this->frameSources[$this->frameNumber]["lzw_code_size"] = ord($this->getImageDataByte("dat", 10, 1));

        $this->framePositions[$this->frameNumber] = array(
            'x' => $this->frameSources[$this->frameNumber]["offset_left"],
            'y' => $this->frameSources[$this->frameNumber]["offset_top"],
        );

        $this->frameDimensions[$this->frameNumber] = array(
            'width' => $this->frameSources[$this->frameNumber]["width"],
            'height' => $this->frameSources[$this->frameNumber]["height"],
        );

        // Decoding
        $this->orgvars[$this->frameNumber]["transparent_color_flag"] = $this->frameSources[$this->frameNumber]["transparent_color_flag"];
        $this->orgvars[$this->frameNumber]["transparent_color_index"] = $this->frameSources[$this->frameNumber]["transparent_color_index"];
        $this->orgvars[$this->frameNumber]["delay_time"] = $this->frameSources[$this->frameNumber]["delay_time"];
        $this->orgvars[$this->frameNumber]["disposal_method"] = $this->frameSources[$this->frameNumber]["disposal_method"];
        $this->orgvars[$this->frameNumber]["offset_left"] = $this->frameSources[$this->frameNumber]["offset_left"];
        $this->orgvars[$this->frameNumber]["offset_top"] = $this->frameSources[$this->frameNumber]["offset_top"];

        // Updating the max width
        if ($this->gifMaxWidth < $this->frameSources[$this->frameNumber]["width"]) {

            $this->gifMaxWidth = $this->frameSources[$this->frameNumber]["width"];
        }

        // Updating the max height
        if ($this->gifMaxHeight < $this->frameSources[$this->frameNumber]["height"]) {

            $this->gifMaxHeight = $this->frameSources[$this->frameNumber]["height"];
        }
    }

    private function getImageDataByte(string $type, int $start, int $length): string
    {
        if ($type == "ext") {

            return substr($this->frameSources[$this->frameNumber]["graphicsextension"], $start, $length);
        }

        // "dat"
        return substr($this->frameSources[$this->frameNumber]["imagedata"], $start, $length);
    }


    private function getImageDataBit(string $type, int $byteIndex, int $bitStart, int $bitLength): int|float
    {
        if ($type == "ext") {

            return $this->readBits(ord(substr($this->frameSources[$this->frameNumber]["graphicsextension"], $byteIndex, 1)), $bitStart, $bitLength);
        }

        // "dat"
        return $this->readBits(ord(substr($this->frameSources[$this->frameNumber]["imagedata"], $byteIndex, 1)), $bitStart, $bitLength);
    }

    private function dualByteVal(string $s): int
    {
        return ord($s[1]) * 256 + ord($s[0]);
    }

    private function readDataStream(int $firstLength): void
    {
        $this->pointerForward($firstLength);
        $length = $this->readByteInt();

        if ($length != 0) {
            while ($length != 0) {
                $this->pointerForward($length);
                $length = $this->readByteInt();
            }
        }
    }

    private function openFile(string $filename): void
    {
        $this->handle = fopen($filename, "rb");
        $this->pointer = 0;

        $imageSize = getimagesize($filename);
        $this->gifWidth = $imageSize[0];
        $this->gifHeight = $imageSize[1];
    }

    private function readByte(int $byteCount): bool|string
    {
        $data = fread($this->handle, $byteCount);
        $this->pointer += $byteCount;

        return $data;
    }

    private function readByteInt(): int
    {
        $data = fread($this->handle, 1);
        ++$this->pointer;

        return ord($data);
    }

    private function readBits(int $byte, int $start, int $length): float|int
    {
        $bin = str_pad(decbin($byte), 8, "0", STR_PAD_LEFT);
        $data = substr($bin, $start, $length);

        return bindec($data);
    }

    private function pointerRewind(int $length): void
    {
        $this->pointer -= $length;
        fseek($this->handle, $this->pointer);
    }

    private function pointerForward(int $length): void
    {
        $this->pointer += $length;
        fseek($this->handle, $this->pointer);
    }

    private function dataPart(int $start, int $length): bool|string
    {
        fseek($this->handle, $start);
        $data = fread($this->handle, $length);
        fseek($this->handle, $this->pointer);

        return $data;
    }

    private function checkByte(int $byte): bool
    {
        if (fgetc($this->handle) == chr($byte)) {

            fseek($this->handle, $this->pointer);
            return true;
        }

        fseek($this->handle, $this->pointer);

        return false;
    }

    private function checkEOF(): bool
    {
        if (fgetc($this->handle) === false) {

            return true;
        }

        fseek($this->handle, $this->pointer);

        return false;
    }

    private function reset(): void
    {
        $this->totalDuration = 0;
        $this->gifMaxHeight = 0;
        $this->gifMaxWidth = 0;
        $this->handle = 0;
        $this->pointer = 0;
        $this->frameNumber = 0;
        $this->frameDimensions = array();
        $this->framePositions = array();
        $this->frameImages = array();
        $this->frameDurations = array();
        $this->globaldata = array();
        $this->orgvars = array();
        $this->frames = array();
        $this->fileHeader = array();
        $this->frameSources = array();
    }
}