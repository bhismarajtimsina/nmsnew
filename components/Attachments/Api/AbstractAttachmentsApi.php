<?php

namespace WCC\Attachments\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SystemActionsStorage;
use WCC\Attachments\Controllers\Controller;
use WCC\Attachments\Storage\AttachmentStorage;

abstract class AbstractAttachmentsApi extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $actionStorage;


    /**
     * @Inject
     * @var AttachmentStorage
     */
    protected $attachmentStorage;


    protected function serveFile(Request $req, ResponseInterface $res, string $path, string $name): ResponseInterface
    {
        if (!is_file($path) || !is_readable($path)) {
            return $res->withStatus(404);
        }

        $size  = filesize($path);
        $mtime = filemtime($path) ?: time();
        $etag  = sprintf('"%x-%x"', $mtime, $size);

        // Conditional GET
        $ifNone = $req->getHeaderLine('If-None-Match');
        $ifMod  = $req->getHeaderLine('If-Modified-Since');
        if ($ifNone === $etag || ($ifMod && strtotime($ifMod) >= $mtime)) {
            return $res->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }

        // MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $path) ?: 'application/octet-stream';
        finfo_close($finfo);

        // inline для превью
        $res = $res
            ->withHeader('Content-Type', $mime)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT')
            ->withHeader('Content-Disposition', 'inline; filename="' . addcslashes($name, '"\\') . '"')
            ->withHeader('Cache-Control', 'private, max-age=86400');

        // Range (один диапазон)
        $range = $req->getHeaderLine('Range'); // bytes=start-end
        $start = 0; $end = $size - 1;
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') $start = (int)$m[1];
            if ($m[2] !== '') $end   = (int)$m[2];
            if ($start > $end || $start >= $size) {
                return $res->withStatus(416)->withHeader('Content-Range', "bytes */$size");
            }
            $length = $end - $start + 1;
            $res = $res->withStatus(206)
                ->withHeader('Content-Range', "bytes $start-$end/$size")
                ->withHeader('Content-Length', (string)$length);

            $fp = fopen($path, 'rb');
            fseek($fp, $start);
            $body = $res->getBody();
            $buf  = 8192;
            $left = $length;
            while ($left > 0 && !feof($fp)) {
                $chunk = fread($fp, min($buf, $left));
                if ($chunk === false) break;
                $body->write($chunk);
                $left -= strlen($chunk);
            }
            fclose($fp);
            return $res;
        }

        // Полная отдача
        $res = $res->withHeader('Content-Length', (string)$size);
        $stream = new \Slim\Psr7\Stream(fopen($path, 'rb'));
        return $res->withBody($stream);
    }
}