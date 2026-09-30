<?php


namespace WCAA\Infrastructure;


class ImgHelper
{
    public static function getBase64FromUrl($image_path) {
        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        $file = file_get_contents($image_path);
        $f = finfo_open();
        $mime_type = finfo_buffer($f, $file, FILEINFO_MIME_TYPE);
        restore_error_handler();
        return 'data:' . $mime_type . ';base64,' . base64_encode($file);
    }
    public static  function getBase64Decode($encoded) {
        $start = strpos($encoded, 'base64');
        $encoded = substr($encoded, $start + 7 );

        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        $decoded = base64_decode($encoded);
        restore_error_handler();
        return $decoded;
    }
    public static  function getMimeType($binaryFile) {
        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        $f = finfo_open();
        $mime_type = finfo_buffer($f, $binaryFile, FILEINFO_MIME_TYPE);
        restore_error_handler();
        return $mime_type;
    }
    public static function saveBinary($binary, $path) {
        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        file_put_contents($path, $binary);
        restore_error_handler();
        return true;
    }
    public static function loadImage($url) {
        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        $image = file_get_contents($url);
        restore_error_handler();
        return $image;
    }
}