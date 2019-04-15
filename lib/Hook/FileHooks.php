<?php

/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Piotr Bator <prbator@gmail.com>
 * @copyright Piotr Bator 2017
 */

namespace OCA\Maps\Hook;

use OC\Files\Filesystem;
use OC\Files\View;
use OCP\Files\FileInfo;
use OCP\ILogger;
use OCP\Files\Node;
use OCP\Files\IRootFolder;
use OCP\Util;
use OCP\Share;

use OCA\Maps\Service\PhotofilesService;

/**
 * Handles files events
 */
class FileHooks {

    private $photofilesService;

    private $logger;

    private $root;

    public function __construct(IRootFolder $root, PhotofilesService $photofilesService, ILogger $logger, $appName) {
        $this->photofilesService = $photofilesService;
        $this->logger = $logger;
        $this->root = $root;
    }

    public function register() {
        $fileWriteCallback = function(\OCP\Files\Node $node) {
            if($this->isUserNode($node)) {
                $this->photofilesService->safeAddByFile($node);
            }
        };
        $this->root->listen('\OC\Files', 'postWrite', $fileWriteCallback);

        $fileDeletionCallback = function(\OCP\Files\Node $node) {
            if($this->isUserNode($node)) {
                if ($node->getType() === FileInfo::TYPE_FOLDER) {
                    $this->photofilesService->deleteByFolder($node);
                } else {
                    $this->photofilesService->deleteByFile($node);
                }
            }
        };
        $this->root->listen('\OC\Files', 'preDelete', $fileDeletionCallback);

        // this one is triggered when restoring a version of a file
        // and NOT when it's created so we can use it for updating coordinates in DB
        $this->root->listen('\OC\Files', 'postTouch', function(\OCP\Files\Node $node) {
            if ($this->isUserNode($node)) {
                $this->photofilesService->deleteByFile($node);
                $this->photofilesService->addByFile($node);
            }
        });

        Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', $this, 'restore');

        // sharing hooks
        Util::connectHook(\OCP\Share::class, 'post_shared', $this, 'share');
        Util::connectHook(\OCP\Share::class, 'post_unshare', $this, 'unShare');
    }

    public static function share($params) {
        if ($params['shareType'] === Share::SHARE_TYPE_USER) {
            //$targetFilePath = $params['itemTarget'];
            //$sourceUserId = $params['uidOwner'];
            $targetUserId = $params['shareWith'];
            $fileId = $params['fileSource']; // or itemSource
            $this->photofilesService->safeAddByFileIdUserId($fileId, $targetUserId);
        }
    }

    public static function unShare($params) {
        if ($params['shareType'] === Share::SHARE_TYPE_USER) {
            $targetUserId = $params['shareWith'];
            $fileId = $params['fileSource']; // or itemSource
            $this->photofilesService->deleteByFileIdUserId($fileId, $targetUserId);
        }
    }

    public static function restore($params) {
        $node = $this->getNodeForPath($params['filePath']);
        if($this->isUserNode($node)) {
            if ($node->getType() === FileInfo::TYPE_FOLDER) {
                $this->photofilesService->addByFolder($node);
            } else {
                $this->photofilesService->addByFile($node);
            }
        }
    }

    private function getNodeForPath($path) {
        $user = \OC::$server->getUserSession()->getUser();
        $fullPath = Filesystem::normalizePath('/' . $user->getUID() . '/files/' . $path);
        return $this->root->get($fullPath);
    }

    /**
     * Ugly Hack, find API way to check if file is added by user.
     */
    private function isUserNode(\OCP\Files\Node $node) {
        //return strpos($node->getStorage()->getId(), "home::", 0) === 0;
        return $node->getStorage()->instanceOfStorage('\OC\Files\Storage\Home');
    }

}
