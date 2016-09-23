<?php
/**
 * Crop images via focus crop
 *
 * @package Focuspoint\Service
 * @author  Tim Lochmüller
 */

namespace HDNET\Focuspoint\Service;

use HDNET\Focuspoint\Service\WizardHandler\Group;
use HDNET\Focuspoint\Utility\GlobalUtility;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

/**
 * Crop images via focus crop
 *
 * @author Tim Lochmüller
 */
class FocusCropService extends AbstractService
{

    const SIGNAL_tempImageCropped = 'tempImageCropped';

    /**
     * @var Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * get the image
     *
     * @param $src
     * @param $image
     * @param $treatIdAsReference
     *
     * @return \TYPO3\CMS\Core\Resource\File|FileInterface|CoreFileReference|\TYPO3\CMS\Core\Resource\Folder
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
     */
    public function getViewHelperImage($src, $image, $treatIdAsReference)
    {
        $resourceFactory = ResourceFactory::getInstance();
        if ($image instanceof FileReference) {
            $image = $image->getOriginalResource();
        }
        if ($image instanceof CoreFileReference) {
            return $image->getOriginalFile();
        }
        if (!MathUtility::canBeInterpretedAsInteger($src)) {
            return $resourceFactory->retrieveFileOrFolderObject($src);
        }
        if (!$treatIdAsReference) {
            return $resourceFactory->getFileObject($src);
        }
        $image = $resourceFactory->getFileReferenceObject($src);
        return $image->getOriginalFile();
    }

    /**
     * Helper function for view helpers
     *
     * @param $src
     * @param $image
     * @param $treatIdAsReference
     * @param $ratio
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
     *
     * @return string
     */
    public function getCroppedImageSrcForViewHelper($src, $image, $treatIdAsReference, $ratio)
    {
        $file = $this->getViewHelperImage($src, $image, $treatIdAsReference);
        return $this->getCroppedImageSrcByFile($file, $ratio);
    }

    /**
     * Get the cropped image
     *
     * @param string $fileReference
     * @param string $ratio
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcByFileReference($fileReference, $ratio)
    {
        if ($fileReference instanceof FileReference) {
            $fileReference = $fileReference->getOriginalResource();
        }
        if ($fileReference instanceof CoreFileReference) {
            return $this->getCroppedImageSrcByFile($fileReference->getOriginalFile(), $ratio);
        }
        throw new \InvalidArgumentException('The given argument is not a valid file reference', 123671283);
    }

    /**
     * Get the cropped image by File Object
     *
     * @param FileInterface $file
     * @param string $ratio
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcByFile(FileInterface $file, $ratio)
    {
        return $this->getCroppedImageSrcBySrc(
            $file->getPublicUrl(),
            $ratio,
            $file->getProperty('focus_point_x'),
            $file->getProperty('focus_point_y')
        );
    }


    /**
     * Get the cropped image by src
     *
     * @param string $src Relative file name
     * @param string $ratio
     * @param int $x
     * @param int $y
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcBySrc($src, $ratio, $x, $y)
    {
        $absoluteImageName = GeneralUtility::getFileAbsFileName($src);
        if (!is_file($absoluteImageName)) {
            return null;
        }
        $focusPointX = MathUtility::forceIntegerInRange((int)$x, -100, 100, 0);
        $focusPointY = MathUtility::forceIntegerInRange((int)$y, -100, 100, 0);

        if ($focusPointX === 0 && $focusPointY === 0) {
            $connection = GlobalUtility::getDatabaseConnection();
            $row = $connection->exec_SELECTgetSingleRow(
                'uid,focus_point_x,focus_point_y',
                Group::TABLE,
                'relative_file_path = ' . $connection->fullQuoteStr($src, Group::TABLE)
            );
            if ($row) {
                $focusPointX = MathUtility::forceIntegerInRange((int)$row['focus_point_x'], -100, 100, 0);
                $focusPointY = MathUtility::forceIntegerInRange((int)$row['focus_point_y'], -100, 100, 0);
            }
        }

        $hash = function_exists('sha1_file') ? sha1_file($absoluteImageName) : md5_file($absoluteImageName);
        $tempImageFolder = 'typo3temp/focuscrop/';
        $tempImageName = $tempImageFolder . $hash . '-fp-' . preg_replace(
            '/[^0-9a-z-]/',
            '-',
            $ratio
        ) . '-' . $focusPointX . '-' . $focusPointY . '.' . PathUtility::pathinfo(
            $absoluteImageName,
            PATHINFO_EXTENSION
        );
        $tempImageName = preg_replace('/--+/', '-', $tempImageName);
        $absoluteTempImageName = GeneralUtility::getFileAbsFileName($tempImageName);

        if (is_file($absoluteTempImageName)) {
            return $tempImageName;
        }

        $absoluteTempImageFolder = GeneralUtility::getFileAbsFileName($tempImageFolder);
        if (!is_dir($absoluteTempImageFolder)) {
            GeneralUtility::mkdir_deep($absoluteTempImageFolder);
        }

        $imageSizeInformation = getimagesize($absoluteImageName);
        $width = $imageSizeInformation[0];
        $height = $imageSizeInformation[1];

        // dimensions
        /** @var DimensionService $dimensionService */
        $dimensionService = GeneralUtility::makeInstance(DimensionService::class);
        list($focusWidth, $focusHeight) = $dimensionService->getFocusWidthAndHeight($width, $height, $ratio);
        $cropMode = $dimensionService->getCropMode($width, $height, $ratio);
        list($sourceX, $sourceY) = $dimensionService->calculateSourcePosition(
            $cropMode,
            $width,
            $height,
            $focusWidth,
            $focusHeight,
            $focusPointX,
            $focusPointY
        );

        $cropService = GeneralUtility::makeInstance(CropService::class);
        $cropService->createImage(
            $absoluteImageName,
            $focusWidth,
            $focusHeight,
            $sourceX,
            $sourceY,
            $absoluteTempImageName
        );

        $this->emitTempImageCropped($tempImageName);

        return $tempImageName;
    }

    /**
     * Emit tempImageCropped signal
     *
     * @param string $tempImageName
     */
    protected function emitTempImageCropped($tempImageName)
    {
        $this->getSignalSlotDispatcher()->dispatch(__CLASS__, self::SIGNAL_tempImageCropped, [$tempImageName]);
    }

    /**
     * Get the SignalSlot dispatcher.
     *
     * @return Dispatcher
     */
    protected function getSignalSlotDispatcher()
    {
        if (!isset($this->signalSlotDispatcher)) {
            $this->signalSlotDispatcher = $this->getObjectManager()->get(Dispatcher::class);
        }
        return $this->signalSlotDispatcher;
    }

    /**
     * Gets the ObjectManager.
     *
     * @return ObjectManager
     */
    protected function getObjectManager()
    {
        return GeneralUtility::makeInstance(ObjectManager::class);
    }
}
