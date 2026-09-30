<?php


namespace WCC\Attachments\Controllers;


use Meklis\PromClient\Client;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Attachments\Models\Attachment;
use WCC\Attachments\Storage\AttachmentStorage;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

/**
 * Class Controller
 * @package WCC\Attachments
 */
class Controller extends \WCC\PrometheusWrapper\Controllers\Controller
{

    /**
     * @var User
     */
    protected $user;

    /**
     * @Inject
     * @var AttachmentStorage
     */
    protected $attachmentStorage;


    function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @param $objectType
     * @param $objectId
     * @param $file
     * @return Attachment
     */
    function uploadObject($objectType, $objectId, $file)
    {

        $clientName = $this->sanitizeFilename($file->getClientFilename());
        $attachment = (new Attachment())
            ->setObjectType($objectType)
            ->setObjectId($objectId)
            ->setExtension($this->getFileExtension($clientName))
            ->setUser($this->user);

        $destinationPath = $attachment->prepareDestinationPath()->getDestinationPath();

        $file->moveTo($destinationPath);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($destinationPath);
        if (!$mime) {
            $mime = $file->getClientMediaType() ?: 'application/octet-stream';
        }

        $preview = $this->makePreviewsByMime($destinationPath, $mime);

        $exif = $this->mimeStartsWith($mime, 'image/') ? $this->getExifDataByPath($destinationPath) : null;

        $attachment->setExtra([
            'extension' => $this->getFileExtension($clientName),
            'filename' => $clientName,
            'mimetype' => $mime,
            'size' => @filesize($destinationPath),
            'exif' => $exif,
            'preview' => $preview,
        ]);

        return $this->attachmentStorage->add($attachment);
    }


    function getThumbPathByUuid($uuid)
    {
        $object = $this->attachmentStorage->getObjectMetaByUUID($uuid);
        $extension = isset($object->getExtra()['preview']['thumbnail_ext']) ? $object->getExtra()['preview']['thumbnail_ext'] : '';
        return "/www/var/attachments/{$object->getObjectType()}/{$object->getObjectId()}/{$uuid}_thumb.{$extension}";
    }


    function getObjectMetaByUUID($uuid)
    {
        return $this->attachmentStorage->getObjectMetaByUUID($uuid)->getAsArrayLite();
    }

    function getPathByUuid($uuid)
    {
        $object = $this->attachmentStorage->getObjectMetaByUUID($uuid);
        return "/www/var/attachments/{$object->getObjectType()}/{$object->getObjectId()}/{$uuid}.{$object->getExtension()}";
    }

    function deleteAttachment($uuid)
    {
        $object = $this->attachmentStorage->getObjectMetaByUUID($uuid);
        $this->attachmentStorage->delete($object);
        return $this;
    }

    function getAttachmentsListByObject($objectType, $objectId)
    {
        $objects = [];
        foreach ($this->attachmentStorage->fetchByObject($objectType, $objectId) as $object) {
            $obj = $object->getAsArrayLite();
            $obj['url'] = "/api/v1/component/attachments/object/{$obj['uuid']}";
            if (isset($obj['extra']['preview']['thumbnail_ext'])) {
                $obj['preview'] = "/api/v1/component/attachments/thumb/{$obj['uuid']}";
            } else {
                $obj['preview'] = "/upload/no-image-icon.png";
            }
            unset($obj['user']['role']['permissions'], $obj['extra']['preview']);
            $objects[] = $obj;
        }
        return $objects;
    }


    private function mimeStartsWith($mime, $prefix)
    {
        return substr($mime, 0, strlen($prefix)) === $prefix;
    }

    private function makePreviewsByMime($path, $mime)
    {
        try {
            if ($this->mimeStartsWith($mime, 'image/')) {
                return $this->makeImageThumb($path, 1024);
            } elseif ($this->mimeStartsWith($mime, 'video/')) {
                return $this->makeVideoPreviews($path, 3, 360, 4);
            } elseif ($mime === 'application/pdf') {
                return $this->makePdfPreview($path, 1200);
            } elseif ($this->mimeStartsWith($mime, 'audio/')) {
                return $this->makeAudioWave($path, 1200, 300);
            }
        } catch (\Throwable $e) {
            return ['error' => 'preview_failed', 'message' => $e->getMessage()];
        }
        return ['note' => 'no_preview_for_mime'];
    }

    private function makeImageThumb($path, $targetW = 1024)
    {
        $base = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME);
        $out = [];
        $im2 = new \Imagick($path);
        $webp = $base . '_thumb.webp';
        $im2->setImageFormat('webp');
        $im2->setImageCompressionQuality(80);
        $im2->writeImage($webp);
        $im2->clear();
        $im2->destroy();
        $out['thumbnail'] = $this->removeDir($webp);
        $out['thumbnail_ext'] = 'webp';
        return $out;
    }

    private function makeVideoPreviews($path, $thumbSec = 3, $thumbH = 480, $clipSec = 4)
    {
        $base = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME);
        $thumb = $base . '_thumb.jpg';
        $preview = $base . '_preview.mp4';

        $cmd1 = sprintf('ffmpeg -y -ss %d -i %s -frames:v 1 -q:v 2 -vf scale=-2:%d %s 2>&1',
            (int)$thumbSec, escapeshellarg($path), (int)$thumbH, escapeshellarg($thumb));
        $cmd2 = sprintf('ffmpeg -y -ss %d -i %s -t %d -an -vf scale=-2:%d -c:v libx264 -preset veryfast -crf 23 -movflags +faststart %s 2>&1',
            max(0, (int)$thumbSec - 1), escapeshellarg($path), (int)$clipSec, (int)$thumbH, escapeshellarg($preview));

        @shell_exec($cmd1);
        @shell_exec($cmd2);

        return [
            'thumbnail' => $this->removeDir($thumb),
            'thumbnail_ext' => 'jpg',
            'preview_mp4' => $this->removeDir($preview)
        ];
    }

    private function makePdfPreview($path, $maxW = 1200)
    {
        // IsCoderAuthorized — рендерим первую страницу в JPEG через gs
        $jpgTmp = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME) . '.jpg';
        $cmd = sprintf(
            'gs -q -dNOPAUSE -dBATCH -sDEVICE=jpeg -dFirstPage=1 -dLastPage=1 -r144 -sOutputFile=%s %s 2>&1',
            escapeshellarg($jpgTmp), escapeshellarg($path)
        );
        @shell_exec($cmd);
        $result = $this->makeImageThumb($jpgTmp, $maxW);
        unlink($jpgTmp);
        return $result;
    }

    private function makeAudioWave($path, $w = 1200, $h = 300)
    {
        $png = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($path, PATHINFO_FILENAME) . '_thumb.png';
        $cmd = sprintf('ffmpeg -y -i %s -lavfi "showwavespic=s=%dx%d" -frames:v 1 %s 2>&1',
            escapeshellarg($path), (int)$w, (int)$h, escapeshellarg($png));
        @shell_exec($cmd);
        return ['thumbnail' => $this->removeDir($png), 'thumbnail_ext' => 'png'];
    }

    protected function getExifDataByPath($destinationPath)
    {
        $meta = null;
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($destinationPath, 'ANY_TAG', true);
                if ($exif) {
                    $meta = [];
                    $meta['date'] = isset($exif['EXIF']['DateTimeOriginal']) ? $exif['EXIF']['DateTimeOriginal'] : null;
                    $meta['make'] = isset($exif['IFD0']['Make']) ? $exif['IFD0']['Make'] : null;
                    $meta['model'] = isset($exif['IFD0']['Model']) ? $exif['IFD0']['Model'] : null;
                    $meta['lens'] = isset($exif['EXIF']['LensModel']) ? $exif['EXIF']['LensModel'] : null;
                    $meta['width'] = isset($exif['COMPUTED']['Width']) ? $exif['COMPUTED']['Width'] : null;
                    $meta['height'] = isset($exif['COMPUTED']['Height']) ? $exif['COMPUTED']['Height'] : null;

                    if (!empty($exif['GPS'])) {
                        $GPS = $exif['GPS'];
                        $meta['gps'] = [
                            'lat' => (isset($GPS['GPSLatitude']) && isset($GPS['GPSLatitudeRef']))
                                ? $this->gpsToFloat($GPS['GPSLatitude'], $GPS['GPSLatitudeRef'])
                                : null,
                            'lon' => (isset($GPS['GPSLongitude']) && isset($GPS['GPSLongitudeRef']))
                                ? $this->gpsToFloat($GPS['GPSLongitude'], $GPS['GPSLongitudeRef'])
                                : null,
                            'alt' => isset($GPS['GPSAltitude']) ? $GPS['GPSAltitude'] : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        return $meta;
    }

    protected function gpsToFloat($coord, $hemisphere)
    {
        $d = explode('/', $coord[0]);
        $deg = $d[0] / ($d[1] ?? 1);
        $m = explode('/', $coord[1]);
        $min = $m[0] / ($m[1] ?? 1);
        $s = explode('/', $coord[2]);
        $sec = $s[0] / ($s[1] ?? 1);
        $sign = ($hemisphere == 'S' || $hemisphere == 'W') ? -1 : 1;
        return $sign * ($deg + $min / 60 + $sec / 3600);
    }

    protected function sanitizeFilename($name)
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        return substr($name, 0, 200);
    }

    protected function getFileExtension($filename)
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return $ext !== '' ? strtolower($ext) : null;
    }

    protected function removeDir($path)
    {
        return str_replace(pathinfo($path, PATHINFO_DIRNAME) . '/', '', $path);
    }
}
