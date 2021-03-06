<?php

namespace Jarves\Controller\Admin;

use FOS\RestBundle\Request\ParamFetcher;
use Jarves\ACL;
use Jarves\ACLRequest;
use Jarves\Filesystem\WebFilesystem;
use Jarves\Jarves;
use Jarves\Objects;
use Jarves\PageStack;
use Jarves\Utils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Jarves\Exceptions\AccessDeniedException;
use Jarves\Exceptions\FileNotFoundException;
use Jarves\Exceptions\FileUploadException;
use Jarves\Exceptions\InvalidArgumentException;
use Jarves\File\FileInfo;
use Jarves\File\FileSize;
use Jarves\Model\Base\FileQuery;
use FOS\RestBundle\Controller\Annotations as Rest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    /**
     * @var Jarves
     */
    protected $jarves;

    /**
     * @var PageStack
     */
    protected $pageStack;

    /**
     * @var WebFilesystem
     */
    protected $webFilesystem;

    /**
     * @var Objects
     */
    protected $objects;

    /**
     * @var Utils
     */
    protected $utils;

    /**
     * @var ACL
     */
    protected $acl;

    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->jarves = $this->container->get('jarves');
        $this->pageStack = $this->container->get('jarves.page_stack');
        $this->objects = $this->container->get('jarves.objects');
        $this->webFilesystem = $this->container->get('jarves.filesystem.web');
        $this->utils = $this->container->get('jarves.utils');
        $this->acl = $this->container->get('jarves.acl');
    }


    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Removes a file or folder (recursively) in /web"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path")
     *
     * @Rest\Delete("/admin/file")
     *
     * @param string $path
     *
     * @return bool
     */
    public function deleteFileAction($path)
    {
        $this->checkAccess($path);
        $this->checkAccess($path, ACL::MODE_DELETE);

        if (!$file = $this->webFilesystem->getFile($path)) {
            return false;
        }

        FileQuery::create()->filterByPath($path)->delete();

        if ($result = $this->webFilesystem->remove($path)) {
            $this->newFeed($file, 'deleted');
        }

        return $result;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Creates a file in /web"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path")
     * @Rest\RequestParam(name="content", requirements=".*", strict=false, description="The file content")
     *
     * @Rest\Put("/admin/file")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array|boolean
     */
    public function createFileAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $content = $paramFetcher->get('content');

        $pseudoItem = [
            'path' => $path,
            'type' => FileInfo::FILE
        ];

        //are we allowed to create this type of file?
        $aclRequest = ACLRequest::create('jarves/file', $pseudoItem)
            ->onlyAddMode()
            ->setPrimaryObjectItem($pseudoItem);
        $this->checkOrThrow($aclRequest);

        //are we allowed to update the target folder?
        $aclRequest = ACLRequest::create('jarves/file', ['path' => dirname($path)])
            ->onlyUpdateMode();
        $this->checkOrThrow($aclRequest);

        if ($this->webFilesystem->has($path)) {
            return ['targetExists' => true];
        }

        $result = $this->webFilesystem->write($path, $content);
        if ($result) {
            $this->newFeed($path, 'created');
        }

        return $result;
    }

    /**
     * @param ACLRequest $aclRequest
     * @throws AccessDeniedException
     */
    protected function checkOrThrow(ACLRequest $aclRequest)
    {
        if (!$this->acl->check($aclRequest)) {
            throw new AccessDeniedException('Access denied');
        }
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Moves a file in /web to $target in /web"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path")
     * @Rest\RequestParam(name="target", requirements=".*", strict=true, description="The target file path")
     * @Rest\RequestParam(name="overwrite", requirements=".*", default="false", description="If the target should be overwritten")
     *
     * @Rest\Post("/admin/file/move")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array|bool returns [targetExists => true] when the target exists and $overwrite=false, otherwise true/false.
     */
    public function moveFileAction(ParamFetcher $paramFetcher)
    {
        $path = trim($paramFetcher->get('path'));
        $target = trim($paramFetcher->get('target'));
        $overwrite = filter_var($paramFetcher->get('overwrite'), FILTER_VALIDATE_BOOLEAN);

        if (!$overwrite && $this->webFilesystem->has($target)) {
            return ['targetExists' => true];
        }

        //are we allowed to update the old file?
        $aclRequest = ACLRequest::create('jarves/file', ['path' => $path])
            ->onlyAddMode();
        $this->checkOrThrow($aclRequest);

        //are we allowed to create this type of file?
        $newFile = $this->webFilesystem->getFile($path)->toArray();
        $newFile['path'] = $target;
        $aclRequest = ACLRequest::create('jarves/file', $newFile)
            ->setPrimaryObjectItem($newFile)
            ->onlyAddMode();
        $this->checkOrThrow($aclRequest);

        $this->newFeed($path, 'moved', sprintf('from %s to %s', $path, $target));
        $result = $this->webFilesystem->move($path, $target);

        return $result;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Moves or copies files in /web to $target in /web"
     * )
     *
     * @Rest\RequestParam(name="files", requirements=".*", map=true, strict=true, description="The file paths")
     * @Rest\RequestParam(name="target", requirements=".*", strict=true, description="The target file path")
     * @Rest\RequestParam(name="overwrite", requirements=".*", strict=true, default="false", description="If the target should be overwritten")
     * @Rest\RequestParam(name="move", requirements=".*", strict=true, default="false", description="If files should be moved (cut&paste) or copied (copy&paste)")
     *
     * @Rest\Post("/admin/file/paste")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array|bool returns [targetExists => true] when a target exists and $overwrite=false, otherwise true/false.
     */
    public function pasteAction(ParamFetcher $paramFetcher)
    {
        $files = $paramFetcher->get('files');
        $target = $paramFetcher->get('target');
        $overwrite = filter_var($paramFetcher->get('overwrite'), FILTER_VALIDATE_BOOLEAN);
        $move = filter_var($paramFetcher->get('move'), FILTER_VALIDATE_BOOLEAN);

        $this->checkAccess($target);
        foreach ($files as $file) {
            $this->checkAccess($file);

            $newPath = $target . '/' . basename($file);
            if (!$overwrite && $this->webFilesystem->has($newPath)) {
                return ['targetExists' => true];
            }

            $this->newFeed($file, $move ? 'moved': 'copied', sprintf('from %s to %s', $file, $newPath));
        }

        return $this->webFilesystem->paste($files, $target, $move ? 'move' : 'copy');
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Creates a folder in /web"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path")
     *
     * @Rest\Put("/admin/file/dir")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return bool
     */
    public function createFolderAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');

        $pseudoItem = [
            'path' => $path,
            'type' => FileInfo::DIR
        ];

        //are we allowed to create this type of dir?
        $aclRequest = ACLRequest::create('jarves/file', $pseudoItem)
            ->onlyAddMode()
            ->setPrimaryObjectItem($pseudoItem);
        $this->checkOrThrow($aclRequest);

        //are we allowed to update the target folder?
        $aclRequest = ACLRequest::create('jarves/file', ['path' => dirname($path)])
            ->onlyUpdateMode();
        $this->checkOrThrow($aclRequest);

        if ($result = $this->webFilesystem->mkdir($path)) {
            $this->newFeed($path, 'created');
        }

        return $result;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Checks the file access"
     * )
     *
     * @param string $path
     * @param int $mode
     * @throws AccessDeniedException
     */
    protected function checkAccess($path, $mode = ACL::MODE_UPDATE)
    {
        $file = null;

        if ('/' !== substr($path, 0, 1)) {
            $path = '/' . $path;
        }

        $file = $this->webFilesystem->getFile($path);

        $aclRequest = ACLRequest::create('jarves/file', $file->toArray())
            ->setMode($mode);

        $this->checkOrThrow($aclRequest);
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Prepares a file upload process"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path")
     * @Rest\RequestParam(name="name", requirements=".*", strict=true, description="The file path")
     * @Rest\RequestParam(name="overwrite", requirements=".*", default="false", description="If the target should be overwritten")
     * @Rest\RequestParam(name="autoRename", requirements=".*", default="false", description="If the target name should be autoRenamed ($name-n) when already exists")
     *
     * @Rest\Post("/admin/file/upload/prepare")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array[renamed => bool, name => string, exist => bool, ready => bool]
     */
    public function prepareUploadAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $name = $paramFetcher->get('name');
        $overwrite = filter_var($paramFetcher->get('overwrite'), FILTER_VALIDATE_BOOLEAN);
        $autoRename = filter_var($paramFetcher->get('autoRename'), FILTER_VALIDATE_BOOLEAN);

        $oriName = $name;
        $newPath = ($path == '/') ? '/' . $name : $path . '/' . $name;

        $overwrite = filter_var($overwrite, FILTER_VALIDATE_BOOLEAN);
        $autoRename = filter_var($autoRename, FILTER_VALIDATE_BOOLEAN);

        $this->checkAccess($path);

        $res = array();

        if ($name != $oriName) {
            $res['renamed'] = true;
            $res['name'] = $name;
        }

        $exist = $this->webFilesystem->has($newPath);
        if ($exist && !$overwrite) {
            if ($autoRename) {
                //find new name
                $extension = '';
                $firstName = $oriName;
                $lastDot = strrpos($oriName, '.');
                if (false !== $lastDot) {
                    $firstName = substr($oriName, 0, $lastDot);
                    $extension = substr($oriName, $lastDot + 1);
                }

                $i = 0;
                do {
                    $i++;
                    $name = $firstName . '-' . $i . ($extension ? '.' . $extension : '');
                    $newPath = ($path == '/') ? '/' . $name : $path . '/' . $name;
                    if (!$this->webFilesystem->has($newPath)) {
                        break;
                    }
                } while (true);

                $res['renamed'] = true;
                $res['name'] = $name;
            } else {
                $res['exist'] = true;

                return $res;
            }
        }

        $this->webFilesystem->write(
            $newPath,
            "file-is-being-uploaded-by-" . hash('sha512', $this->pageStack->getSession()->getId())
        );
        $res['ready'] = true;

        return $res;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Uploads a file to $path with $name as name"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The target path")
     * @Rest\RequestParam(name="name", requirements=".*", strict=false, description="The file name if you want a different")
     * @ #Rest\RequestParam(name="overwrite", requirements=".*", default="false", description="If the target should be overwritten")
     * @Rest\RequestParam(name="file", strict=false, description="The file")
     *
     * @Rest\Post("/admin/file/upload")
     *
     * @param Request $request
     * @param ParamFetcher $paramFetcher
     *
     * @return string
     * @throws FileUploadException
     * @throws InvalidArgumentException
     * @throws AccessDeniedException
     */
    public function doUploadAction(Request $request, ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $overwriteName = $paramFetcher->get('name');
//        $overwrite = filter_var($paramFetcher->get('overwrite'), FILTER_VALIDATE_BOOLEAN);

        /** @var $file UploadedFile */
        $file = $request->files->get('file');
        if (null == $file) {
            throw new InvalidArgumentException("There is no file uploaded.");
        }

        $name = $file->getClientOriginalName();
        if ($overwriteName) {
            $name = $overwriteName;
        }

        if ($file->getError()) {
            $error = sprintf(
                ('Failed to upload the file %s to %s. Error: %s'),
                $name,
                $path,
                $file->getErrorMessage()
            );
            throw new FileUploadException($error);
        }

        $newPath = ($path == '/') ? '/' . $name : $path . '/' . $name;
        if ($this->webFilesystem->has($newPath)) {
//            if (!$overwrite) {
                if ($this->webFilesystem->has($newPath)) {
                    $content = $this->webFilesystem->read($newPath);

                    $check = "file-is-being-uploaded-by-" . hash('sha512', $this->pageStack->getSession()->getId());
                    if ($content != $check) {
                        //not our file, so cancel
                        throw new FileUploadException(sprintf(
                            'The target file is currently being uploaded by someone else.'
                        ));
                    }
                } else {
                    throw new FileUploadException(sprintf('The target file has not be initialized.'));
                }
//            }
        }

        $fileToAdd = ['path' => $path];

        $aclRequest = ACLRequest::create('jarves/file')
            ->setPrimaryObjectItem($fileToAdd)
            ->onlyUpdateMode();

        if (!$this->acl->check($aclRequest)
        ) {
            throw new AccessDeniedException(sprintf('No access to file `%s`', $path));
        }

        $content = file_get_contents($file->getPathname());
        $result = $this->webFilesystem->write($newPath, $content);
        @unlink($file->getPathname());

        if ($result) {
            $this->newFeed($newPath, 'uploaded', 'to ' . $newPath);
        }

        return $newPath;
    }

    /**
     * @param string|FileInfo $path
     * @param string $verb
     * @param string $message
     */
    protected function newFeed($path, $verb, $message = '')
    {
        $file = $path;
        if (!($path instanceof FileInfo)) {
            try {
                $file = $this->webFilesystem->getFile($path);
            } catch (\Exception $e){
                return;
            }
        }

        if ($file instanceof FileInfo) {
            $this->utils->newNewsFeed(
                $this->objects,
                'jarves/file',
                $file->toArray(),
                $verb,
                $message
            );
        }
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Returns the content of the file. If $path is a directory it returns all containing files as array"
     * )
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     *
     * @Rest\Get("/admin/file")
     *
     * @param string $path
     *
     * @return array|null|string array for directory, string for file content, null if not found.
     */
    public function getContentAction($path)
    {
        if (!$file = $this->getFile($path)) {
            return null;
        }

        // todo: check for Read permission

        if ($file['type'] == 'dir') {
            return $this->getFiles($path);
        } else {
            return $this->webFilesystem->read($path);
        }
    }

    /**
     * Returns a list of files for a folder.
     *
     * @param string $path
     *
     * @return array|null
     */
    protected function getFiles($path)
    {
        if (!$this->getFile($path)) {
            return null;
        }

        //todo, create new option 'show hidden files' in user settings and depend on that

        $files = $this->webFilesystem->getFiles($path);

        return $this->prepareFiles($files);
    }

    /**
     * Adds 'writeAccess' and imageInformation to $files.
     *
     * @param FileInfo[] $files
     * @param bool $showHiddenFiles
     * @return array
     */
    protected function prepareFiles($files, $showHiddenFiles = false)
    {
        $result = [];

        $blacklistedFiles = array('/index.php' => 1, '/install.php' => 1);

        foreach ($files as $key => $file) {
            $aclRequest = ACLRequest::create('jarves/file', ['path' => $file->getPath()])->onlyListingMode();
            if (!$this->acl->check($aclRequest)
            ) {
                continue;
            }

            if (isset($blacklistedFiles[$file->getPath()]) | (!$showHiddenFiles && substr($file->getName(), 0, 1) == '.')) {
                continue;
            } else {
                $aclRequest = ACLRequest::create('jarves/file', ['path' => $file->getPath()])->onlyUpdateMode();
                $fileArray = $file->toArray();
                $fileArray['writeAccess'] = $this->acl->check($aclRequest);
                $this->appendImageInformation($fileArray);
                $result[] = $fileArray;
            }
        }

        return $result;
    }

    /**
     * Adds image information (dimensions/imageType).
     *
     * @param array $file
     */
    protected function appendImageInformation(&$file)
    {
        $imageTypes = array('jpg', 'jpeg', 'png', 'bmp', 'gif');

        if (array_search($file['extension'], $imageTypes) !== false) {
            $content = $this->webFilesystem->read($file['path']);

            $size = new FileSize();
            $size->setHandleFromBinary($content);

            $file['imageType'] = $size->getType();
            $size = $size->getSize();
            if ($size) {
                $file['dimensions'] = ['width' => $size[0], 'height' => $size[1]];
            }
        }
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Searches for files"
     * )
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The target path")
     * @Rest\QueryParam(name="q", requirements=".*", strict=true, description="Search query")
     * @Rest\QueryParam(name="depth", requirements="[0-9]+", default=1, description="Depth")
     *
     * @Rest\Get("/admin/file/search")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array
     */
    public function searchAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $q = $paramFetcher->get('q');
        $depth = $paramFetcher->get('depth');

        $files = $this->webFilesystem->search($path, $q, $depth);

        return $this->prepareFiles($files);
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Gets information about a single file"
     * )
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     *
     * @Rest\Get("/admin/file/single")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return array|null null if not found or not access
     */
    public function getFileAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');

        return $this->getFile($path);
    }

    /**
     * Returns file information as array.
     *
     * @param string|integer $path
     * @return array|null
     */
    protected function getFile($path)
    {
        $file = $this->webFilesystem->getFile($path);
        $file = $file->toArray();

        $aclRequest = ACLRequest::create('jarves/file', $file)
            ->onlyListingMode();

        if (!$file || !$this->acl->check($aclRequest)) {
            return null;
        }

        $file['writeAccess'] = $this->acl->check($aclRequest->onlyUpdateMode());

        $this->appendImageInformation($file);

        return $file;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Displays a thumbnail/resized version of a image"
     * )
     *
     * This exists the process and sends a `content-type: image/png` http container.
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     * @Rest\QueryParam(name="width", requirements="[0-9]+", description="The image width")
     * @Rest\QueryParam(name="height", requirements="[0-9]*", description="The image height")
     *
     * @Rest\Get("/admin/file/preview")
     *
     * @param ParamFetcher $paramFetcher
     * @return Response
     */
    public function showPreviewAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $width = $paramFetcher->get('width');
        $height = $paramFetcher->get('height');

        if (!$width && !$height) {
            $width = 50;
            $height = 50;
        }

        if (is_numeric($path)) {
            $path = $this->webFilesystem->getPath($path);
        }

        $this->checkAccess($path, ACL::MODE_VIEW);

        $file = $this->webFilesystem->getFile($path);
        if ($file->isDir()) {
            return;
        }

        $ifModifiedSince = $this->pageStack->getRequest()->headers->get('If-Modified-Since');
        if (isset($ifModifiedSince) && (strtotime($ifModifiedSince) == $file->getModifiedTime())) {
            // Client's cache IS current, so we just respond '304 Not Modified'.

            $response = new Response();
            $response->setStatusCode(304);
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()).' GMT');
            return $response;
        }

        $image = null;
        try {
            $image = $this->webFilesystem->getResizeMax($path, $width, $height);
        } catch (\Exception $e) {
            $image = $this->webFilesystem->getResizeMax('bundles/jarves/images/broken-image.png', $width, $height);
        }

        $expires = 3600; //1 h
        $response = new Response();
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Pragma', 'public');
        $response->headers->set('Cache-Control', 'max-age=' . $expires);
        $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()) . ' GMT');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');

        ob_start();
        imagepng($image->getResult(), null, 8);
        $imageData = ob_get_contents();
        ob_end_clean();

        $response->setContent($imageData);
        return $response;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Views the file content directly in the browser with a proper Content-Type and cache headers"
     * )
     *
     * Views the file content directly in the browser with a proper Content-Type and cache headers.
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     *
     * @Rest\Get("/admin/file/content")
     *
     * @param ParamFetcher $paramFetcher
     */
    public function viewFileAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');

        if (is_numeric($path)) {
            $path = $this->webFilesystem->getPath($path);
        }
        $this->checkAccess($path, ACL::MODE_VIEW);

        $file = $this->webFilesystem->getFile($path);
        if ($file->isDir()) {
            return;
        }

        $ifModifiedSince = $this->pageStack->getRequest()->headers->get('If-Modified-Since');
        if (isset($ifModifiedSince) && (strtotime($ifModifiedSince) == $file->getModifiedTime())) {
            // Client's cache IS current, so we just respond '304 Not Modified'.
            $response = new Response();
            $response->setStatusCode(304);
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()).' GMT');
            return $response;
        }

        $content = $this->webFilesystem->read($path);
        $mime = $file->getMimeType();

        $expires = 3600; //1 h
        $response = new Response();
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Pragma', 'public');
        $response->headers->set('Cache-Control', 'max-age=' . $expires);
        $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()) . ' GMT');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');

        $response->setContent($content);
        return $response;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Saves the file content"
     * )
     *
     * @Rest\RequestParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     * @Rest\RequestParam(name="contentEncoding", requirements="plain|base64", default="plain", description="The $content contentEncoding.")
     * @Rest\RequestParam(name="content", description="The file content")
     *
     * @Rest\Post("/admin/file/content")
     *
     * @param ParamFetcher $paramFetcher
     *
     * @return bool
     */
    public function setContentAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');
        $content = $paramFetcher->get('content');
        $contentEncoding = $paramFetcher->get('contentEncoding');

        $this->checkAccess($path);
        if ('base64' === $contentEncoding) {
            $content = base64_decode($content);
        }

        if ($result = $this->webFilesystem->write($path, $content)) {
            $this->newFeed($path, 'changed content');
        }

        return $result;
    }

    /**
     * @ApiDoc(
     *  section="File Manager",
     *  description="Displays a (complete) image (with cache-headers)"
     * )
     *
     * @Rest\QueryParam(name="path", requirements=".+", strict=true, description="The file path or its ID")
     *
     * @Rest\Get("/admin/file/image")
     *
     * @param ParamFetcher $paramFetcher
     * @return Response
     */
    public function showImageAction(ParamFetcher $paramFetcher)
    {
        $path = $paramFetcher->get('path');

        if (is_numeric($path)) {
            $path = $this->webFilesystem->getPath($path);
        }

        $this->checkAccess($path, ACL::MODE_VIEW);
        $file = $this->webFilesystem->getFile($path);
        if ($file->isDir()) {
            return;
        }

        $ifModifiedSince = $this->pageStack->getRequest()->headers->get('If-Modified-Since');
        if (isset($ifModifiedSince) && (strtotime($ifModifiedSince) == $file->getModifiedTime())) {
            // Client's cache IS current, so we just respond '304 Not Modified'.
            $response = new Response();
            $response->setStatusCode(304);
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()).' GMT');
            return $response;
        }

        $content = $this->webFilesystem->read($path);
        $image = \PHPImageWorkshop\ImageWorkshop::initFromString($content);

        $result = $image->getResult();

        $size = new FileSize();
        $size->setHandleFromBinary($content);


        $expires = 3600; //1 h
        $response = new Response();
        $response->headers->set('Content-Type', 'png' == $size->getType() ? 'image/png' : 'image/jpeg');
        $response->headers->set('Pragma', 'public');
        $response->headers->set('Cache-Control', 'max-age=' . $expires);
        $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', $file->getModifiedTime()) . ' GMT');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');

        ob_start();

        if ('png' === $size->getType()) {
            imagepng($result, null, 3);
        } else {
            imagejpeg($result, null, 100);
        }

        $response->setContent(ob_get_contents());
        ob_end_clean();

        return $response;
    }

}
