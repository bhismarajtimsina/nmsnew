<?php


namespace WCC\QrGenerator\Controllers;


use WCAA\App;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\StorageInterface;
use WCC\UtelsIntegration\Models\BoxObject;



use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Class Controller
 * @package WCC\QrGenerator
 */
class Controller extends \WCC\PrometheusWrapper\Controllers\Controller
{

    /**
     * @var User
     */
    protected $user;


    function saveToFile(\Endroid\QrCode\Writer\Result\ResultInterface $result)
    {
        $result->saveToFile(__DIR__ . '/../../../public/upload/');
    }

    function getBase64(\Endroid\QrCode\Writer\Result\ResultInterface $result)
    {
        $png = $result->getString();
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * @param $object
     * @param $addLabel
     * @param $addLogo
     * @param float $size
     * @param $customLabel
     * @return \Endroid\QrCode\Writer\Result\ResultInterface
     * @throws \Exception
     */
    function generateQrByObject($object, $addLabel = true, $addLogo = true, $size = 400, $customLabel = '')
    {
        $qrCode = QrCode::create($this->getUrlByObject($object))
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->setSize($size)           // размер изображения
            ->setMargin(0)          // отступы
            ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $logo  = null;
        if($addLogo) {
            $logo = Logo::create(__DIR__ . '/../resources/logo-white.png')
                ->setResizeToWidth(round($size / 3))
                ->setPunchoutBackground(true);
        }
        $label = null;
        if($addLabel) {
            $label = Label::create($this->getLabelByObject($object))
                ->setTextColor(new Color(0, 0, 0));
        }
        if(trim($customLabel) && $addLabel) {
            $label = Label::create($customLabel)
                ->setTextColor(new Color(0, 0, 0));
        }
        $writer = new PngWriter();
        $result = $writer->write($qrCode, $logo, $label);
        return $result;
    }


    function getUrlByObject($object)
    {
        $baseUrl = App::getInstance()->conf('system.http_address');

        if ($object instanceof Device) {
            return $baseUrl . '/devices/' . $object->id;
        } else if($object instanceof DeviceInterface) {
            return "{$baseUrl}/devices/{$object->getDevice()->getId()}/interface/{$object->getBindKey()}";
        } else if($object instanceof User) {
            return $baseUrl . '/management/user/' . $object->id;
        }

        if(class_exists(BoxObject::class) && $object instanceof BoxObject) {
            return "{$baseUrl}/utels-pon-boxes/detail/{$object->getId()}";
        }

        throw new \Exception('Object type not supported');
    }

    function getLabelByObject($object)
    {

        $label = '';
        if ($object instanceof Device) {
            $label = "{$object->getIp()} - {$object->getName()}";
        } else if($object instanceof DeviceInterface) {
            $label = "{$object->getName()}" . ($object->getDescription() ? ' - ' . $object->getDescription() : '');
        } else if($object instanceof User) {
            $label = "{$object->getLogin()}";
        }

        if(class_exists(BoxObject::class) && $object instanceof BoxObject) {
            $label = "{$object->getNumber()}";
        }
        if($label) {
            $label = str_replace(["\n"], ' ', $label);
            return str_replace(["і", 'ї', 'Ї', 'І'], 'i', $label);
        }
        throw new \Exception('Object type not supported');
    }
}
