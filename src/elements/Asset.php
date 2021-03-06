<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\elements;

use Craft;
use craft\base\Element;
use craft\base\LocalVolumeInterface;
use craft\base\Volume;
use craft\base\VolumeInterface;
use craft\elements\actions\CopyReferenceTag;
use craft\elements\actions\DeleteAssets;
use craft\elements\actions\DownloadAssetFile;
use craft\elements\actions\Edit;
use craft\elements\actions\EditImage;
use craft\elements\actions\RenameFile;
use craft\elements\actions\ReplaceFile;
use craft\elements\actions\View;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\errors\FileException;
use craft\events\AssetEvent;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Image;
use craft\helpers\StringHelper;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\models\AssetTransform;
use craft\models\VolumeFolder;
use craft\records\Asset as AssetRecord;
use craft\validators\AssetLocationValidator;
use craft\validators\DateTimeValidator;
use craft\volumes\Temp;
use DateTime;
use yii\base\ErrorHandler;
use yii\base\Exception;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\base\UnknownPropertyException;

/**
 * Asset represents an asset element.
 *
 * @property bool $hasThumb Whether the file has a thumbnail
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Asset extends Element
{
    // Constants
    // =========================================================================

    // Events
    // -------------------------------------------------------------------------

    /**
     * @event AssetEvent The event that is triggered before an asset is uploaded to volume.
     */
    const EVENT_BEFORE_HANDLE_FILE = 'beforeHandleFile';

    // Location error codes
    // -------------------------------------------------------------------------

    const ERROR_DISALLOWED_EXTENSION = 'disallowed_extension';
    const ERROR_FILENAME_CONFLICT = 'filename_conflict';

    // Validation scenarios
    // -------------------------------------------------------------------------

    const SCENARIO_FILEOPS = 'fileOperations';
    const SCENARIO_INDEX = 'index';
    const SCENARIO_CREATE = 'create';
    const SCENARIO_REPLACE = 'replace';

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Asset');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle()
    {
        return 'asset';
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return AssetQuery The newly created [[AssetQuery]] instance.
     */
    public static function find(): ElementQueryInterface
    {
        return new AssetQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $volumes = Craft::$app->getVolumes();

        if ($context === 'index') {
            $sourceIds = $volumes->getViewableVolumeIds();
        } else {
            $sourceIds = $volumes->getAllVolumeIds();
        }

        $additionalCriteria = $context === 'settings' ? ['parentId' => ':empty:'] : [];

        $tree = Craft::$app->getAssets()->getFolderTreeByVolumeIds($sourceIds, $additionalCriteria);

        $sourceList = self::_assembleSourceList($tree, $context !== 'settings');

        // Add the customized temporary upload source
        if ($context !== 'settings') {
            $temporaryUploadFolder = Craft::$app->getAssets()->getCurrentUserTemporaryUploadFolder();
            $temporaryUploadFolder->name = Craft::t('app', 'Temporary Uploads');
            $sourceList[] = self::_assembleSourceInfoForFolder($temporaryUploadFolder, false);
        }

        return $sourceList;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];

        if (preg_match('/^folder:(\d+)/', $source, $matches)) {
            $folderId = $matches[1];

            $folder = Craft::$app->getAssets()->getFolderById($folderId);
            /** @var Volume $volume */
            $volume = $folder->getVolume();

            // View for public URLs
            if ($volume->hasUrls) {
                $actions[] = Craft::$app->getElements()->createAction(
                    [
                        'type' => View::class,
                        'label' => Craft::t('app', 'View asset'),
                    ]
                );
            }

            // Download
            $actions[] = DownloadAssetFile::class;

            // Edit
            $actions[] = Craft::$app->getElements()->createAction(
                [
                    'type' => Edit::class,
                    'label' => Craft::t('app', 'Edit asset'),
                ]
            );

            $userSessionService = Craft::$app->getUser();

            // Rename File
            if (
                $userSessionService->checkPermission('removeFromVolume:'.$volume->id)
                &&
                $userSessionService->checkPermission('uploadToVolume:'.$volume->id)
            ) {
                $actions[] = RenameFile::class;
                $actions[] = EditImage::class;
            }

            // Replace File
            if ($userSessionService->checkPermission('uploadToVolume:'.$volume->id)) {
                $actions[] = ReplaceFile::class;
            }

            // Copy Reference Tag
            $actions[] = Craft::$app->getElements()->createAction(
                [
                    'type' => CopyReferenceTag::class,
                    'elementType' => static::class,
                ]
            );

            // Delete
            if ($userSessionService->checkPermission('removeFromVolume:'.$volume->id)) {
                $actions[] = DeleteAssets::class;
            }
        }

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['filename', 'extension', 'kind'];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'filename' => Craft::t('app', 'Filename'),
            'size' => Craft::t('app', 'File Size'),
            'dateModified' => Craft::t('app', 'File Modification Date'),
            'elements.dateCreated' => Craft::t('app', 'Date Uploaded'),
            'elements.dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'filename' => ['label' => Craft::t('app', 'Filename')],
            'size' => ['label' => Craft::t('app', 'File Size')],
            'kind' => ['label' => Craft::t('app', 'File Kind')],
            'imageSize' => ['label' => Craft::t('app', 'Image Size')],
            'width' => ['label' => Craft::t('app', 'Image Width')],
            'height' => ['label' => Craft::t('app', 'Image Height')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'dateModified' => ['label' => Craft::t('app', 'File Modified Date')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'filename',
            'size',
            'dateModified',
        ];
    }

    /**
     * Transforms an asset folder tree into a source list.
     *
     * @param array $folders
     * @param bool  $includeNestedFolders
     *
     * @return array
     */
    private static function _assembleSourceList(array $folders, bool $includeNestedFolders = true): array
    {
        $sources = [];

        foreach ($folders as $folder) {
            $sources[] = self::_assembleSourceInfoForFolder($folder, $includeNestedFolders);
        }

        return $sources;
    }

    /**
     * Transforms an VolumeFolderModel into a source info array.
     *
     * @param VolumeFolder $folder
     * @param bool         $includeNestedFolders
     *
     * @return array
     */
    private static function _assembleSourceInfoForFolder(VolumeFolder $folder, bool $includeNestedFolders = true): array
    {
        $source = [
            'key' => 'folder:'.$folder->id,
            'label' => $folder->parentId ? $folder->name : Craft::t('site', $folder->name),
            'hasThumbs' => true,
            'criteria' => ['folderId' => $folder->id],
            'data' => [
                'upload' => $folder->volumeId === null ? true : Craft::$app->getUser()->checkPermission('uploadToVolume:'.$folder->volumeId)
            ]
        ];

        if ($includeNestedFolders) {
            $source['nested'] = self::_assembleSourceList(
                $folder->getChildren(),
                true
            );
        }

        return $source;
    }

    // Properties
    // =========================================================================

    /**
     * @var int|null Source ID
     */
    public $volumeId;

    /**
     * @var int|null Folder ID
     */
    public $folderId;

    /**
     * @var string|null Folder path
     */
    public $folderPath;

    /**
     * @var string|null Filename
     */
    public $filename;

    /**
     * @var string|null Kind
     */
    public $kind;

    /**
     * @var int|null Width
     */
    public $width;

    /**
     * @var int|null Height
     */
    public $height;

    /**
     * @var int|null Size
     */
    public $size;

    /**
     * @var string|null Focal point
     */
    public $focalPoint;

    /**
     * @var \DateTime|null Date modified
     */
    public $dateModified;

    /**
     * @var string|null New file location
     */
    public $newLocation;

    /**
     * @var string|null Location error code
     * @see AssetLocationValidator::validateAttribute()
     */
    public $locationError;

    /**
     * @var string|null New filename
     */
    public $newFilename;

    /**
     * @var int|null New folder id
     */
    public $newFolderId;

    /**
     * @var string|null The temp file path
     */
    public $tempFilePath;

    /**
     * @var bool Whether Asset should avoid filename conflicts when saved.
     */
    public $avoidFilenameConflicts = false;

    /**
     * @var string|null The suggested filename in case of a conflict.
     */
    public $suggestedFilename;

    /**
     * @var string|null The filename that was used that caused a conflict.
     */
    public $conflictingFilename;

    /**
     * @var bool Whether the associated file should be preserved if the asset record is deleted.
     */
    public $keepFileOnDelete = false;

    /**
     * @var
     */
    private $_transform;

    /**
     * @var string
     */
    private $_transformSource = '';

    /**
     * @var VolumeInterface|null
     */
    private $_volume;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    /** @noinspection PhpInconsistentReturnPointsInspection */
    public function __toString()
    {
        try {
            if ($this->_transform !== null) {
                return (string)$this->getUrl();
            }

            return parent::__toString();
        } catch (\Exception $e) {
            ErrorHandler::convertExceptionToError($e);
        }
    }

    /**
     * Checks if a property is set.
     *
     * This method will check if $name is one of the following:
     *
     * - a magic property supported by [[Element::__isset()]]
     * - an image transform handle
     *
     * @param string $name The property name
     *
     * @return bool Whether the property is set
     */
    public function __isset($name): bool
    {
        return parent::__isset($name) || Craft::$app->getAssetTransforms()->getTransformByHandle($name);
    }

    /**
     * Returns a property value.
     *
     * This method will check if $name is one of the following:
     *
     * - a magic property supported by [[Element::__get()]]
     * - an image transform handle
     *
     * @param string $name The property name
     *
     * @return mixed The property value
     * @throws UnknownPropertyException if the property is not defined
     * @throws InvalidCallException if the property is write-only.
     */
    public function __get($name)
    {
        try {
            return parent::__get($name);
        } catch (UnknownPropertyException $e) {
            // Is $name a transform handle?
            $transform = Craft::$app->getAssetTransforms()->getTransformByHandle($name);

            if ($transform) {
                // Duplicate this model and set it to that transform
                $model = new Asset();

                // Can't just use attributes() here because we'll get thrown into an infinite loop.
                foreach ($this->attributes() as $attributeName) {
                    $model->$attributeName = $this->$attributeName;
                }

                $model->setFieldValues($this->getFieldValues());
                $model->setTransform($transform);

                return $model;
            }

            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $names = parent::datetimeAttributes();
        $names[] = 'dateModified';

        return $names;
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();

        $rules[] = [['volumeId', 'folderId', 'width', 'height', 'size'], 'number', 'integerOnly' => true];
        $rules[] = [['dateModified'], DateTimeValidator::class];
        $rules[] = [['filename', 'kind'], 'required'];
        $rules[] = [['kind'], 'string', 'max' => 50];
        $rules[] = [['newLocation'], AssetLocationValidator::class, 'avoidFilenameConflicts' => $this->avoidFilenameConflicts];
        $rules[] = [['newLocation'], 'required', 'on' => [self::SCENARIO_CREATE, self::SCENARIO_FILEOPS]];
        $rules[] = [['tempFilePath'], 'required', 'on' => [self::SCENARIO_CREATE, self::SCENARIO_REPLACE]];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_INDEX] = [];

        return $scenarios;
    }

    /**
     * @inheritdoc
     */
    public function getIsEditable(): bool
    {
        return Craft::$app->getUser()->checkPermission(
            'uploadToVolume:'.$this->volumeId
        );
    }

    /**
     * Returns an <img> tag based on this asset.
     *
     * @return \Twig_Markup|null
     */
    public function getImg()
    {
        if ($this->kind === 'image' && $this->getHasUrls()) {
            $img = '<img src="'.$this->getUrl().'" width="'.$this->getWidth().'" height="'.$this->getHeight().'" alt="'.Html::encode($this->title).'" />';

            return Template::raw($img);
        }

        return null;
    }

    /**
     * Returns the asset’s volume folder.
     *
     * @return VolumeFolder
     * @throws InvalidConfigException if [[folderId]] is missing or invalid
     */
    public function getFolder(): VolumeFolder
    {
        if ($this->folderId === null) {
            throw new InvalidConfigException('Asset is missing its folder ID');
        }

        if (($folder = Craft::$app->getAssets()->getFolderById($this->folderId)) === null) {
            throw new InvalidConfigException('Invalid folder ID: '.$this->folderId);
        }

        return $folder;
    }

    /**
     * Returns the asset’s volume.
     *
     * @return VolumeInterface
     * @throws InvalidConfigException if [[volumeId]] is missing or invalid
     */
    public function getVolume(): VolumeInterface
    {
        if ($this->_volume !== null) {
            return $this->_volume;
        }

        if ($this->volumeId === null) {
            return new Temp();
        }

        if (($volume = Craft::$app->getVolumes()->getVolumeById($this->volumeId)) === null) {
            throw new InvalidConfigException('Invalid volume ID: '.$this->volumeId);
        }

        return $this->_volume = $volume;
    }

    /**
     * Sets the transform.
     *
     * @param AssetTransform|string|array|null $transform The transform that should be applied, if any. Can either be the handle of a named transform, or an array that defines the transform settings.
     *
     * @return Asset
     */
    public function setTransform($transform): Asset
    {
        $this->_transform = Craft::$app->getAssetTransforms()->normalizeTransform($transform);

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @param string|array|null $transform The transform that should be applied, if any. Can either be the handle of a named transform, or an array that defines the transform settings.
     *
     * @return string|null
     */
    public function getUrl($transform = null)
    {
        if (!$this->getHasUrls()) {
            return null;
        }

        if (is_array($transform)) {
            if (isset($transform['width'])) {
                $transform['width'] = round($transform['width']);
            }
            if (isset($transform['height'])) {
                $transform['height'] = round($transform['height']);
            }
        }

        if ($transform === null && $this->_transform !== null) {
            $transform = $this->_transform;
        }

        return Craft::$app->getAssets()->getUrlForAsset($this, $transform);
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size)
    {
        if ($this->getHasThumb()) {
            return UrlHelper::resourceUrl('resized/'.$this->id.'/'.$size, [
                Craft::$app->getResources()->dateParam => $this->dateModified->getTimestamp()
            ]);
        } else {
            return UrlHelper::resourceUrl('icons/'.$this->getExtension());
        }
    }

    /**
     * Returns whether the file has a thumbnail.
     *
     * @return bool
     */
    public function getHasThumb(): bool
    {
        return (
            $this->kind === 'image' &&
            $this->getHeight() &&
            $this->getWidth() &&
            (!in_array($this->getExtension(), ['svg', 'bmp'], true) || Craft::$app->getImages()->getIsImagick())
        );
    }

    /**
     * Get the file extension.
     *
     * @return mixed
     */
    public function getExtension()
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }

    /**
     * @return string
     */
    public function getMimeType(): string
    {
        // todo: maybe we should be passing this off to volume types
        // so Local volumes can call FileHelper::getMimeType() (uses magic file instead of ext)
        return FileHelper::getMimeTypeByExtension($this->filename);
    }

    /**
     * Get image height.
     *
     * @param string|array|null $transform The transform that should be applied, if any. Can either be the handle of a named transform, or an array that defines the transform settings.
     *
     * @return bool|float|mixed
     */

    public function getHeight($transform = null)
    {
        if ($transform !== null && !Image::isImageManipulatable(
                $this->getExtension()
            )
        ) {
            $transform = null;
        }

        return $this->_getDimension('height', $transform);
    }

    /**
     * Get image width.
     *
     * @param string|null $transform The optional transform handle for which to get thumbnail.
     *
     * @return bool|float|mixed
     */
    public function getWidth(string $transform = null)
    {
        if ($transform !== null && !Image::isImageManipulatable(
                $this->getExtension()
            )
        ) {
            $transform = null;
        }

        return $this->_getDimension('width', $transform);
    }

    /**
     * @return string
     */
    public function getTransformSource(): string
    {
        if (!$this->_transformSource) {
            Craft::$app->getAssetTransforms()->getLocalImageSource($this);
        }

        return $this->_transformSource;
    }

    /**
     * Set a source to use for transforms for this Assets File.
     *
     * @param string $uri
     */
    public function setTransformSource(string $uri)
    {
        $this->_transformSource = $uri;
    }

    /**
     * Get a file's uri path in the source.
     *
     * @param string|null $filename Filename to use. If not specified, the file's filename will be used.
     *
     * @return string
     */
    public function getUri(string $filename = null): string
    {
        return $this->folderPath.($filename ?: $this->filename);
    }

    /**
     * Return the path where the source for this Asset's transforms should be.
     *
     * @return string
     */
    public function getImageTransformSourcePath(): string
    {
        $volume = $this->getVolume();

        if ($volume instanceof LocalVolumeInterface) {
            return FileHelper::normalizePath($volume->getRootPath().DIRECTORY_SEPARATOR.$this->getUri());
        }

        return Craft::$app->getPath()->getAssetsImageSourcePath().DIRECTORY_SEPARATOR.$this->id.'.'.$this->getExtension();
    }

    /**
     * Get a temporary copy of the actual file.
     *
     * @return string
     */
    public function getCopyOfFile(): string
    {
        $tempFilename = uniqid(pathinfo($this->filename, PATHINFO_FILENAME), true).'.'.$this->getExtension();
        $tempPath = Craft::$app->getPath()->getTempPath().DIRECTORY_SEPARATOR.$tempFilename;
        $this->getVolume()->saveFileLocally($this->getUri(), $tempPath);

        return $tempPath;
    }

    /**
     * Get a stream of the actual file.
     *
     * @return resource
     */
    public function getStream()
    {
        return $this->getVolume()->getFileStream($this->getUri());
    }

    /**
     * Return whether the Asset has a URL.
     *
     * @return bool
     */
    public function getHasUrls(): bool
    {
        /** @var Volume $volume */
        $volume = $this->getVolume();

        return $volume && $volume->hasUrls;
    }

    /**
     * Return the Asset's focal point or null if not an image.
     *
     * @return null|array
     */
    public function getFocalPoint()
    {
        if ($this->kind !== 'image') {
            return null;
        }

        if (!empty($this->focalPoint)) {
            $focal = explode(';', $this->focalPoint);
            if (count($focal) === 2) {
                return ['x' => $focal[0], 'y' => $focal[1]];
            }
        }

        return ['x' => 0.5, 'y' => 0.5];
    }

    // Indexes, etc.
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'filename':
                /** @noinspection CssInvalidPropertyValue - FP */
                return Html::encodeParams('<span style="word-break: break-word;">{filename}</span>', [
                    'filename' => $this->filename,
                ]);

            case 'kind':
                return AssetsHelper::getFileKindLabel($this->kind);

            case 'size':
                return $this->size ? Craft::$app->getFormatter()->asShortSize($this->size) : '';

            case 'imageSize':
                return (($width = $this->getWidth()) && ($height = $this->getHeight())) ? "{$width} × {$height}" : '';

            case 'width':
            case 'height':
                $size = $this->$attribute;

                return ($size ? $size.'px' : '');
        }

        return parent::tableAttributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    public function getEditorHtml(): string
    {
        if (!$this->fieldLayoutId) {
            $this->fieldLayoutId = Craft::$app->getRequest()->getBodyParam('defaultFieldLayoutId');
        }

        $html = Craft::$app->getView()->renderTemplateMacro('_includes/forms', 'textField', [
            [
                'label' => Craft::t('app', 'Filename'),
                'id' => 'newFilename',
                'name' => 'newFilename',
                'value' => $this->filename,
                'errors' => $this->getErrors('newLocation'),
                'first' => true,
                'required' => true,
                'class' => 'renameHelper text'
            ]
        ]);

        $html .= Craft::$app->getView()->renderTemplateMacro('_includes/forms', 'textField', [
            [
                'label' => Craft::t('app', 'Title'),
                'siteId' => $this->siteId,
                'id' => 'title',
                'name' => 'title',
                'value' => $this->title,
                'errors' => $this->getErrors('title'),
                'required' => true
            ]
        ]);

        $html .= parent::getEditorHtml();

        return $html;
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     * @return bool
     */
    public function beforeValidate()
    {
        if (!$this->fieldLayoutId) {
            $this->fieldLayoutId = Craft::$app->getRequest()->getBodyParam('defaultFieldLayoutId');
        }

        if (empty($this->newLocation) && (!empty($this->newFolderId) || !empty($this->newFilename))) {
            $folderId = $this->newFolderId ?: $this->folderId;
            $filename = $this->newFilename ?: $this->filename;
            $this->newLocation = "{folder:{$folderId}}{$filename}";
        }

        if ($this->newLocation || $this->tempFilePath) {
            $event = new AssetEvent(['asset' => $this, 'isNew' => !$this->id]);
            $this->trigger(self::EVENT_BEFORE_HANDLE_FILE, $event);
        }

        // Set the kind based on filename, if not set already
        if (empty($this->kind) && !empty($this->filename)) {
            $this->kind = AssetsHelper::getFileKindByExtension($this->filename);
        }

        if (!$this->id && (!$this->title || $this->title === Craft::t('app', 'New Element'))) {
            // Give it a default title based on the file name
            $this->title = StringHelper::toTitleCase(pathinfo($this->filename, PATHINFO_FILENAME));
        }

        return parent::beforeValidate();
    }

    /**
     * @inheritdoc
     * @throws Exception if reasons
     */
    public function beforeSave(bool $isNew): bool
    {
        if (!parent::beforeSave($isNew)) {
            return false;
        }

        $assetsService = Craft::$app->getAssets();

        // See if we need to perform any file operations
        if ($this->newLocation) {
            list($folderId, $filename) = AssetsHelper::parseFileLocation($this->newLocation);
            $hasNewFolder = $folderId != $this->folderId;
            $hasNewFilename = $filename != $this->filename;
        } else {
            $folderId = $this->folderId;
            $filename = $this->filename;
            $hasNewFolder = $hasNewFilename = false;
        }

        $tempPath = null;

        // Yes/no?
        if ($hasNewFolder || $hasNewFilename || $this->tempFilePath) {

            $oldFolder = $this->folderId ? $assetsService->getFolderById($this->folderId) : null;
            $oldVolume = $oldFolder ? $oldFolder->getVolume() : null;

            $newFolder = $hasNewFolder ? $assetsService->getFolderById($folderId) : $oldFolder;
            $newVolume = $hasNewFolder ? $newFolder->getVolume() : $oldVolume;

            $oldPath = $this->folderId ? $this->getUri() : null;
            $newPath = ($newFolder->path ? rtrim($newFolder->path, '/').'/' : '').$filename;

            // Is this just a simple move/rename within the same volume?
            if (!$this->tempFilePath && $oldFolder !== null && $oldFolder->volumeId == $newFolder->volumeId) {
                $oldVolume->renameFile($oldPath, $newPath);
            } else {
                // Get the temp path
                if ($this->tempFilePath) {
                    $tempPath = $this->tempFilePath;
                } else {
                    $tempFilename = uniqid(pathinfo($filename, PATHINFO_FILENAME), true).'.'.pathinfo($filename, PATHINFO_EXTENSION);
                    $tempPath = Craft::$app->getPath()->getTempPath().DIRECTORY_SEPARATOR.$tempFilename;
                    $oldVolume->saveFileLocally($oldPath, $tempPath);
                }

                // Try to open a file stream
                if (($stream = fopen($tempPath, 'rb')) === false) {
                    FileHelper::removeFile($tempPath);
                    throw new FileException(Craft::t('app', 'Could not open file for streaming at {path}', ['path' => $tempPath]));
                }

                // Delete the old path first.
                if ($this->folderId) {
                    // Delete the old file
                    $oldVolume->deleteFile($oldPath);
                }

                // Upload the file to the new location
                $newVolume->createFileByStream($newPath, $stream, []);
                fclose($stream);
            }

            if ($this->folderId) {
                // Nuke the transforms
                Craft::$app->getAssetTransforms()->deleteAllTransformData($this);
            }

            /** @var Volume $volume */
            $volume = $newFolder->getVolume();

            // Update file properties
            $this->volumeId = $newFolder->volumeId;
            $this->fieldLayoutId = $volume->fieldLayoutId;
            $this->folderId = $folderId;
            $this->folderPath = $newFolder->path;
            $this->filename = $filename;

            // If there was a new file involved, update file data.
            if ($tempPath) {
                $this->kind = AssetsHelper::getFileKindByExtension($filename);

                if ($this->kind === 'image') {
                    list ($this->width, $this->height) = Image::imageSize($tempPath);
                } else {
                    $this->width = null;
                    $this->height = null;
                }

                $this->size = filesize($tempPath);
                $this->dateModified = new DateTime('@'.filemtime($tempPath));

                // Delete the temp file
                FileHelper::removeFile($tempPath);
            }

            $this->newFolderId = null;
            $this->newFilename = null;
            $this->newLocation = null;
            $this->tempFilePath = null;
        }

        return true;
    }

    /**
     * @inheritdoc
     * @throws Exception if reasons
     */
    public function afterSave(bool $isNew)
    {
        // Get the asset record
        if (!$isNew) {
            $record = AssetRecord::findOne($this->id);

            if (!$record) {
                throw new Exception('Invalid asset ID: '.$this->id);
            }
        } else {
            $record = new AssetRecord();
            $record->id = $this->id;
        }

        $record->filename = $this->filename;
        $record->volumeId = $this->volumeId;
        $record->folderId = $this->folderId;
        $record->kind = $this->kind;
        $record->size = $this->size;
        $record->focalPoint = $this->focalPoint;
        $record->width = $this->width;
        $record->height = $this->height;
        $record->dateModified = $this->dateModified;
        $record->save(false);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {
        if (!$this->keepFileOnDelete) {
            $this->getVolume()->deleteFile($this->getUri());
        }

        Craft::$app->getAssetTransforms()->deleteAllTransformData($this);
        parent::afterDelete();
    }

    // Private Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function htmlAttributes(string $context): array
    {
        $attributes = [];

        if ($context === 'index') {
            // Eligible for the image editor?
            $ext = $this->getExtension();
            if (strcasecmp($ext, 'svg') !== 0 && Image::isImageManipulatable($ext)) {
                $attributes['data-editable-image'] = null;
            }
        }

        return $attributes;
    }

    // Private Methods
    // =========================================================================

    /**
     * Return a dimension of the image.
     *
     * @param string                           $dimension 'height' or 'width'
     * @param AssetTransform|string|array|null $transform
     *
     * @return null|float|mixed
     */
    private function _getDimension(string $dimension, $transform)
    {
        if ($this->kind !== 'image') {
            return null;
        }

        if ($transform === null && $this->_transform !== null) {
            $transform = $this->_transform;
        }

        if (!$transform) {
            return $this->$dimension;
        }

        $transform = Craft::$app->getAssetTransforms()->normalizeTransform($transform);

        $dimensions = [
            'width' => $transform->width,
            'height' => $transform->height
        ];

        if (!$transform->width || !$transform->height) {
            // Fill in the blank
            $dimensionArray = Image::calculateMissingDimension($dimensions['width'], $dimensions['height'], $this->width, $this->height);
            $dimensions['width'] = (int)$dimensionArray[0];
            $dimensions['height'] = (int)$dimensionArray[1];
        }

        // Special case for 'fit' since that's the only one whose dimensions vary from the transform dimensions
        if ($transform->mode === 'fit') {
            $factor = max($this->width / $dimensions['width'], $this->height / $dimensions['height']);
            $dimensions['width'] = (int)round($this->width / $factor);
            $dimensions['height'] = (int)round($this->height / $factor);
        }

        return $dimensions[$dimension];
    }
}
