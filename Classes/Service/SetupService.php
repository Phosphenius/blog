<?php

namespace T3G\AgencyPack\Blog\Service;

use T3G\AgencyPack\Blog\Constants;
use T3G\AgencyPack\Blog\Install\ExtensionInstaller;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class SetupService.
 */
class SetupService
{
    /**
     * @var array of created record uids
     */
    protected $recordUidArray = [];

    /**
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    public function determineBlogSetups()
    {
        $setups = [];
        /** @var array $blogRootPages */
        $blogRootPages = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'pid, count(pid) AS cnt',
            'pages',
            'deleted = 0 AND doktype = '.Constants::DOKTYPE_BLOG_POST,
            'pid'
        );
        foreach ($blogRootPages as $blogRootPage) {
            $blogUid = $blogRootPage['pid'];
            if (!array_key_exists($blogUid, $setups)) {
                /** @var array $title */
                $title = $this->getDatabaseConnection()->exec_SELECTgetSingleRow('title', 'pages', 'deleted = 0 AND uid = '.(int) $blogUid);
                if (!is_array($title)) {
                    continue;
                }
                $setups[$blogUid] = [
                    'uid' => $blogUid,
                    'title' => $title['title'],
                    'articleCount' => $blogRootPage['cnt'],
                ];
            }
        }

        return $setups;
    }

    /**
     * @param $uid
     *
     * @return array
     */
    public function getBlogRecordAsArray($uid)
    {
        return $this->getDatabaseConnection()->exec_SELECTgetSingleRow('*', 'pages', 'uid = '.(int) $uid);
    }

    /**
     * @param array $data
     *
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    public function createBlogSetup(array $data)
    {
        $useTemplate = array_key_exists('template', $data) ? (bool) $data['template'] : false;
        $installExtension = array_key_exists('install', $data) ? (bool) $data['install'] : false;
        $title = array_key_exists('title', $data) ? (string) $data['title'] : null;

        if ($installExtension && $this->installExtension('blog_template')) {
            $useTemplate = true;
        }

        $blogSetup = GeneralUtility::getFileAbsFileName('EXT:blog/Configuration/DataHandler/BlogSetupRecords.php');

        $result = false;
        if (file_exists($blogSetup)) {
            /* @noinspection PhpIncludeInspection */
            $blogSetup = require $blogSetup;
            if ($useTemplate) {
                $blogSetup['sys_template']['NEW_SysTemplate']['include_static_file'] = 'EXT:fluid_styled_content/Configuration/TypoScript/Static/,EXT:blog_template/Configuration/TypoScript/BlogTemplate/,EXT:blog/Configuration/TypoScript/Static/';
            }
            if ($title !== null) {
                $blogSetup['pages']['NEW_blogRoot']['title'] = $title;
            }
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($blogSetup, []);
            $result = $dataHandler->process_datamap();
            $this->recordUidArray = array_merge_recursive($this->recordUidArray, $dataHandler->substNEWwithIDs);
            if ($result !== false) {
                $result = true;
                // Update page id in PageTSConfig
                $blogRootUid = (int) $this->recordUidArray['NEW_blogRoot'];
                $blogFolderUid = (int) $this->recordUidArray['NEW_blogFolder'];
                /** @var array $record */
                $record = $this->getDatabaseConnection()->exec_SELECTgetSingleRow('TSconfig', 'pages', 'uid = '.$blogRootUid);
                $this->getDatabaseConnection()->exec_UPDATEquery('pages', 'uid = '.$blogRootUid, [
                    'TSconfig' => str_replace('NEW_blogFolder', $blogFolderUid, $record['TSconfig']),
                ]);

                $blogSetupRelations = GeneralUtility::getFileAbsFileName('EXT:blog/Configuration/DataHandler/BlogSetupRelations.php');
                if (file_exists($blogSetupRelations)) {
                    /* @noinspection PhpIncludeInspection */
                    $blogSetupRelations = require $blogSetupRelations;
                    $blogSetupRelations = $this->replaceNewUids($blogSetupRelations);
                    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                    $dataHandler->start($blogSetupRelations, []);
                    $resultRelations = $dataHandler->process_datamap();
                    $this->recordUidArray = array_merge_recursive($this->recordUidArray, $dataHandler->substNEWwithIDs);
                    if ($resultRelations !== false) {
                        $result = true;
                    }
                }
            }
            if ($result === true) {
                // Replace UIDs in constants
                $sysTemplateUid = (int) $this->recordUidArray['NEW_SysTemplate'];
                $record = $this->getDatabaseConnection()->exec_SELECTgetSingleRow('constants', 'sys_template', 'uid = '.$sysTemplateUid);
                $this->getDatabaseConnection()->exec_UPDATEquery('sys_template', 'uid = '.$sysTemplateUid, [
                    'constants' => str_replace(
                        array_keys($this->recordUidArray),
                        array_values($this->recordUidArray),
                        $record['constants']
                    ),
                ]);
            }
        }

        BackendUtility::setUpdateSignal('updatePageTree');
        return $result;
    }

    /**
     * @param array $setup
     *
     * @return array
     */
    protected function replaceNewUids(array $setup)
    {
        $newSetup = [];
        foreach ($setup as $key => &$value) {
            if (false !== strpos($key, 'NEW')) {
                foreach ($this->recordUidArray as $newId => $uid) {
                    $key = str_replace($newId, $uid, $key);
                }
            }
            if (is_array($value)) {
                /* @noinspection ReferenceMismatchInspection */
                $value = $this->replaceNewUids($value);
            } else {
                if (false !== strpos($value, 'NEW')) {
                    foreach ($this->recordUidArray as $newId => $uid) {
                        /* @noinspection ReferenceMismatchInspection */
                        $value = str_replace($newId, $uid, $value);
                    }
                }
            }
            /* @noinspection ReferenceMismatchInspection */
            $newSetup[$key] = $value;
        }

        return $newSetup;
    }

    /**
     * @param string $extKey
     *
     * @return bool
     *
     * @throws \InvalidArgumentException
     */
    protected function installExtension($extKey)
    {
        $installer = GeneralUtility::makeInstance(ExtensionInstaller::class, $extKey);
        $databaseQueries = [];
        $customMessages = '';

        return $installer->performUpdate($databaseQueries, $customMessages);
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}
