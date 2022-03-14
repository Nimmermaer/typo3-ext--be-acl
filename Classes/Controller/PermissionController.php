<?php
namespace JBartels\BeAcl\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2005 Sebastian Kurfuerst (sebastian@garbage-group.de)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use JBartels\BeAcl\Exception\RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Backend\Tree\View\PageTreeView;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use JBartels\BeAcl\View\BackendTemplateView;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;

/**
 * Backend ACL - Replacement for "web->Access"
 *
 * @author  Sebastian Kurfuerst <sebastian@garbage-group.de>
 *
 * Bugfixes applied:
 * #25942, #25835, #13019, #13176, #13175 Jan Bartels
 */
class PermissionController extends \TYPO3\CMS\Beuser\Controller\PermissionController
{
    private const SESSION_PREFIX = 'tx_Beuser_';
    private const ALLOWED_ACTIONS = ['index', 'edit', 'update'];
    private const DEPTH_LEVELS = [1, 2, 3, 4, 10];
    private const RECURSIVE_LEVELS = 10;

    protected $defaultViewObjectName = BackendTemplateView::class;

    protected $aclList = [];

    protected $currentAction;

    protected $aclTypes = [0, 1];

    /**
     * ACL table
     * @var string
     */
    protected $table = 'tx_beacl_acl';

    /*****************************
     *
     * Listing and Form rendering
     *
     *****************************/

    /**
     * Initialize action
     *
     * @return void
     */
    protected function initializeAction()
    {

        parent::initializeAction();

        if(empty($this->returnUrl)) {
            $this->returnUrl = $this->uriBuilder->reset()->setArguments([
                'action' => 'index',
                'id' => $this->id
                ])->buildBackendUri();
        }
    }

    /**
     * Initializes view
     *
     * @param ViewInterface $view The view to be initialized
     * @return void
     */
    protected function initializeView(string $template = ''): void
    {

        parent::initializeView($template);

        // Add custom JS for Acl permissions
        if ($template !== '') {
            $this->view->setTemplateRootPaths(['EXT:beuser/Resources/Private/Templates', 'EXT:be_acl/Resources/Private/Templates']);
            $this->view->setPartialRootPaths(['EXT:beuser/Resources/Private/Partials', 'EXT:be_acl/Resources/Private/Partials']);
            $this->view->setLayoutRootPaths(['EXT:beuser/Resources/Private/Layouts', 'EXT:be_acl/Resources/Private/Layouts']);
            if(file_exists(GeneralUtility::getFileAbsFileName('EXT:be_acl/Resources/Private/Templates/Permission/' . $template . '.html'))) {
                $this->view->setTemplatePathAndFilename(
                    GeneralUtility::getFileAbsFileName('EXT:be_acl/Resources/Private/Templates/Permission/' . $template . '.html')
                );
            }
            $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/BeAcl/AclPermissions');
        }

    }

    /**
     * Index action
     *
     * @return void
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->view->assignMultiple([
            'currentId' => $this->id,
            'viewTree' => $this->getTree(),
            'beUsers' => BackendUtility::getUserNames(),
            'beGroups' => BackendUtility::getGroupNames(),
            'depth' => $this->depth,
            'depthOptions' => $this->getDepthOptions(),
            'depthBaseUrl' => $this->uriBuilder->buildUriFromRoute('system_BeuserTxPermission', [
                'id' => $this->id,
                'depth' => '${value}',
                'action' => 'index',
            ]),
            'editUrl' => $this->uriBuilder->buildUriFromRoute('system_BeuserTxPermission', [
                'action' => 'edit',
            ]),
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('system_BeuserTxPermission', [
                'id' => $this->id,
                'depth' => $this->depth,
                'action' => 'index',
            ]),
        ]);

        // Get ACL configuration
        $beAclConfig = $this->getExtConf();

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;
        $enableFilterSelector = $beAclConfig['enableFilterSelector'] ? 1 : 0;

        $this->view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);
        $this->view->assign('enableFilterSelector', $enableFilterSelector);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        /*
         *  User ACLs
         */
        $userAcls = $this->aclObjects(0, $beAclConfig);
        // If filter is enabled, filter user ACLs according to selection
        if ($enableFilterSelector) {
            $usersSelected = array_filter($userAcls, function ($var) {
                return !empty($var['selected']);
            });
        }
         // No filter enabled, so show all user ACLs
        else {
            $usersSelected = $userAcls;
        }
        $this->view->assign('userSelectedAcls', $usersSelected);

        // Options for user filter
        $this->view->assign('userFilterOptions', [
            'options' => $userAcls,
            'title' => $GLOBALS['LANG']->getLL('aclUsers'),
            'id' => 'userAclFilter'
        ]);

        /*
         *  Group ACLs
         */
        $groupAcls = $this->aclObjects(1, $beAclConfig);
        // If filter is enabled, filter group ACLs according to selection
        if ($enableFilterSelector) {
            $groupsSelected = array_filter($groupAcls, function ($var) {
                return !empty($var['selected']);
            });
        }
        // No filter enabled, so show all group ACLs
        else {
            $groupsSelected = $groupAcls;
        }
        $this->view->assign('groupSelectedAcls', $groupsSelected);

        // Options for group filter
        $this->view->assign('groupFilterOptions', [
            'options' => $groupAcls,
            'title' => $GLOBALS['LANG']->getLL('aclGroups'),
            'id' => 'groupAclFilter'
        ]);

        /*
         *  ACL Tree
         */
        $this->buildACLtree(array_keys($userAcls), array_keys($groupAcls));
        $this->view->assign('aclList', $this->aclList);

        return $this->htmlResponse($this->moduleTemplate->setContent($this->view->render())->renderContent());
    }

    /**
     * Edit action
     *
     * @return void
     */
    public function editAction(ServerRequestInterface $request): ResponseInterface
    {

        $lang = $this->getLanguageService();
        $selectNone = $lang->sL('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectNone');
        $selectUnchanged = $lang->sL('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectUnchanged');

        // Owner selector
        $beUserDataArray = [0 => $selectNone];
        foreach (BackendUtility::getUserNames() as $uid => $row) {
            $beUserDataArray[$uid] = $row['username'] ?? '';
        }
        $beUserDataArray[-1] = $selectUnchanged;

        // Group selector
        $beGroupDataArray = [0 => $selectNone];
        foreach (BackendUtility::getGroupNames() as $uid => $row) {
            $beGroupDataArray[$uid] = $row['title'] ?? '';
        }
        $beGroupDataArray[-1] = $selectUnchanged;

        $this->view->assignMultiple([
            'id' => $this->id,
            'depth' => $this->depth,
            'currentBeUser' => $this->pageInfo['perms_userid'] ?? 0,
            'beUserData' => $beUserDataArray,
            'currentBeGroup' => $this->pageInfo['perms_groupid'] ?? 0,
            'beGroupData' => $beGroupDataArray,
            'pageInfo' => $this->pageInfo,
            'returnUrl' => $this->returnUrl,
            'recursiveSelectOptions' => $this->getRecursiveSelectOptions(),
            'formAction' => (string)$this->uriBuilder->buildUriFromRoute('system_BeuserTxPermission', [
                'action' => 'update',
                'id' => $this->id,
                'depth' => $this->depth,
                'returnUrl' => $this->returnUrl,
            ]),
        ]);

        // Get ACL configuration
        $beAclConfig = $this->getExtConf();

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;

        $this->view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        // ACL CODE
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
        $statement = $queryBuilder
            ->select('*')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter( $this->id, \PDO::PARAM_INT ) )
            )
            ->execute();
        $pageAcls = array();
        while ($result = $statement->fetch()) {
            $pageAcls[] = $result;
        }

        $userGroupSelectorOptions = array();
        foreach (array(1 => 'Group', 0 => 'User') as $type => $label) {
            $option = new \stdClass();
            $option->key = $type;
            $option->value = LocalizationUtility::translate('LLL:EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf:acl' . $label,
                'be_acl');
            $userGroupSelectorOptions[] = $option;
        }
        $this->view->assign('userGroupSelectorOptions', $userGroupSelectorOptions);
        $this->view->assign('pageAcls', $pageAcls);


        return $this->htmlResponse($this->moduleTemplate->setContent($this->view->render())->renderContent());
    }

    /**
     * Update action
     *
     * @param array $data
     * @param array $mirror
     * @return void
     */
    protected function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array)($request->getParsedBody()['data'] ?? []);
        $mirror = (array)($request->getParsedBody()['mirror'] ?? []);
        // Process data map
        $tce = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\DataHandling\\DataHandler');
        $tce->stripslashes_values = 0;
        $tce->start($data, array());
        $tce->process_datamap();

        parent::updateAction($request);

        return $this->responseFactory->createResponse(303)
            ->withHeader('location', $this->returnUrl);
    }

    /*****************************
     *
     * Helper functions
     *
     *****************************/

    protected function getCurrentAction()
    {
        if (is_null($this->currentAction)) {
            $this->currentAction = $this->request->getControllerActionName();
        }
        return $this->currentAction;
    }

    /**
     *
     * @global array $BE_USER
     * @param int $type
     * @param array $conf
     * @return array
     */
    protected function aclObjects($type, $conf)
    {
        global $BE_USER;
        $aclObjects = [];
        $currentSelection = [];

        // Run query
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
        $statement = $queryBuilder
            ->select('uid', 'pid', 'object_id', 'type', 'recursive')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq('type', $queryBuilder->createNamedParameter( $type, \PDO::PARAM_INT ) )
            )
            ->execute();
        // Process results
        while ($result = $statement->fetch()) {
            $aclObjects[$result['object_id']] = $result;
        }
        // Check results
        if (empty($aclObjects)) {
            return $aclObjects;
        }

        // If filter selector is enabled, then determine currently selected items
        if ($conf['enableFilterSelector']) {
            // get current selection from UC, merge data, write it back to UC
            $currentSelection = is_array($BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type]) ? $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] : array();

            $currentSelectionOverride_raw = GeneralUtility::_GP('tx_beacl_objsel');
            $currentSelectionOverride = array();
            if (is_array($currentSelectionOverride_raw[$type])) {
                foreach ($currentSelectionOverride_raw[$type] as $tmp) {
                    $currentSelectionOverride[$tmp] = $tmp;
                }
            }
            if ($currentSelectionOverride) {
                $currentSelection = $currentSelectionOverride;
            }

            $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] = $currentSelection;
            $BE_USER->writeUC($BE_USER->uc);
        }

        // create option data
        foreach ($aclObjects as $k => &$v) {
            $v['selected'] = (in_array($k, $currentSelection)) ? 1 : 0;
        }

        return $aclObjects;
    }

    /**
     * Creates an ACL tree which correlates with tree for current page
     * Datastructure: pageid - userId / groupId - permissions
     * eg. $this->aclList[pageId][type][object_id] = [
     *      'permissions' => 31
     *      'recursive' => 1,
     *      'pid' => 10
     * ];
     *
     * @param array $users - user ID list
     * @param array $groups - group ID list
     */
    protected function buildACLtree($users, $groups)
    {
        $startPerms = [
            0 => [],
            1 => []
        ];

        // get permissions in the starting point for users and groups
        $rootLine = BackendUtility::BEgetRootLine($this->id);
        $currentPage = array_shift($rootLine); // needed as a starting point

        // Iterate rootline, looking for recursive ACLs that may apply to the current page
        foreach ($rootLine as $level => $values) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
            $statement = $queryBuilder
                ->select('uid', 'pid', 'type', 'object_id', 'permissions', 'recursive')
                ->from('tx_beacl_acl')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter( $values['uid'], \PDO::PARAM_INT ) ),
                    $queryBuilder->expr()->eq('recursive', $queryBuilder->createNamedParameter( 1, \PDO::PARAM_INT ) )
                )
                ->execute();
            while ($result = $statement->fetch()) {
                // User type ACLs
                if ($result['type'] == 0
                    && in_array($result['object_id'], $users)
                    && !array_key_exists($result['object_id'], $startPerms[0])
                ) {
                    $startPerms[0][$result['object_id']] = [
                        'uid' => $result['uid'],
                        'permissions' => $result['permissions'],
                        'recursive' => $result['recursive'],
                        'pid' => $result['pid']
                    ];
                }
                // Group type ACLs
                elseif ($result['type'] == 1
                    && in_array($result['object_id'], $groups)
                    && !array_key_exists($result['object_id'], $startPerms[1])
                ) {
                    $startPerms[1][$result['object_id']] = [
                        'uid' => $result['uid'],
                        'permissions' => $result['permissions'],
                        'recursive' => $result['recursive'],
                        'pid' => $result['pid']
                    ];
                }
            }
        }

        $this->traversePageTree_acl($startPerms, $currentPage['uid']);
    }

    protected function getDefaultAclMetaData()
    {
        return array_fill_keys($this->aclTypes, [
            'acls' => 0,
            'inherited' => 0
        ]);
    }

    /**
     * Adds count meta data to the page ACL list
     * @param array $pageData
     */
    protected function addAclMetaData(&$pageData)
    {
        if (!array_key_exists('meta', $pageData)) {
            $pageData['meta'] = $this->getDefaultAclMetaData();
        }

        foreach ($this->aclTypes as $type) {
            $pageData['meta'][$type]['inherited'] = (isset($pageData[$type]) && is_array($pageData[$type])) ? count($pageData[$type]) : 0;
        }
    }

    /**
     * Finds ACL permissions for specified page and its children recursively, given
     * the parent ACLs.
     * @param array $parentACLs
     * @param int $pageId
     */
    protected function traversePageTree_acl($parentACLs, $pageId)
    {
        // Fetch ACLs aasigned to given page
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
        $statement = $queryBuilder
            ->select('uid', 'pid', 'type', 'object_id', 'permissions', 'recursive')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter( $pageId, \PDO::PARAM_INT ) )
            )
            ->execute();

        $hasNoRecursive = array();
        $this->aclList[$pageId] = $parentACLs;

        $this->addAclMetaData($this->aclList[$pageId]);

        while ($result = $statement->fetch()) {
            $aclData = array(
                'uid' => $result['uid'],
                'permissions' => $result['permissions'],
                'recursive' => $result['recursive'],
                'pid' => $result['pid']
            );

            // Non-recursive ACL
            if ($result['recursive'] == 0) {
                $this->aclList[$pageId][$result['type']][$result['object_id']] = $aclData;
                $hasNoRecursive[$pageId][$result['type']][$result['object_id']] = $aclData;
            }
            else {
                // Recursive ACL
                // Add to parent ACLs for sub-pages
                $parentACLs[$result['type']][$result['object_id']] = $aclData;
                // If there also is a non-recursive ACL for this object_id, that takes precedence
                // for this page. Otherwise, add it to the ACL list.
                if (is_array($hasNoRecursive[$pageId][$result['type']][$result['object_id']])) {
                    $this->aclList[$pageId][$result['type']][$result['object_id']] = $hasNoRecursive[$pageId][$result['type']][$result['object_id']];
                } else {
                    $this->aclList[$pageId][$result['type']][$result['object_id']] = $aclData;
                }
            }

            // Increment ACL count
            $this->aclList[$pageId]['meta'][$result['type']]['acls'] += 1;
        }

        // Find child pages and their ACL permissions
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $statement = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter( $pageId, \PDO::PARAM_INT ) )
            )
            ->execute();
        while ($result = $statement->fetch()) {
            $this->traversePageTree_acl($parentACLs, $result['uid']);
        }
    }

    public function handleAjaxRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $action = $parsedBody['action'] ?? null;
        $this->initializeView();
        $response = parent::handleAjaxRequest($request);

        if($action == 'delete_acl') {
            $response = $this->deleteAcl($request, $response);
        }

        return $response;
    }

    protected function deleteAcl(ServerRequestInterface $request, ResponseInterface $response)
    {
        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');
        $GLOBALS['LANG']->getLL('aclUsers');

        $postData = $request->getParsedBody();
        $aclUid = !empty($postData['acl']) ? $postData['acl'] : null;

        if (!MathUtility::canBeInterpretedAsInteger($aclUid)) {
            return $this->errorResponse($response, $GLOBALS['LANG']->getLL('noAclId'), 400);
        }
        $aclUid = (int)$aclUid;
        // Prepare command map
        $cmdMap = [
            $this->table => [
                $aclUid => ['delete' => 1]
            ]
        ];

        try {
            // Process command map
            $tce = GeneralUtility::makeInstance(DataHandler::class);
            $tce->stripslashes_values = 0;
            $tce->start(array(), $cmdMap);
            $this->checkModifyAccess($this->table, $aclUid, $tce);
            $tce->process_cmdmap();
        } catch (\Exception $ex) {
            return $this->errorResponse($response, $ex->getMessage(), 403);
        }

        $body = [
            'title' => $GLOBALS['LANG']->getLL('aclSuccess'),
            'message' => $GLOBALS['LANG']->getLL('aclDeleted')
        ];
        // Return result
        $response->getBody()->write(json_encode($body));
        return $response;
    }

    protected function checkModifyAccess($table, $id, DataHandler $tcemainObj)
    {
        // Check modify access
        $modifyAccessList = $tcemainObj->checkModifyAccessList($table);
        // Check basic permissions and circumstances:
        if (!isset($GLOBALS['TCA'][$table]) || $tcemainObj->tableReadOnly($table) || !is_array($tcemainObj->cmdmap[$table]) || !$modifyAccessList) {
            throw new RuntimeException($GLOBALS['LANG']->getLL('noPermissionToModifyAcl'));
        }

        // Check table / id
        if (!$GLOBALS['TCA'][$table] || !$id) {
            throw new RuntimeException(sprintf($GLOBALS['LANG']->getLL('noEditAccessToAclRecord'), $id, $table));
        }

        // Check edit access
        $hasEditAccess = $tcemainObj->BE_USER->recordEditAccessInternals($table, $id, false, false, true);
        if (!$hasEditAccess) {
            throw new RuntimeException(sprintf($GLOBALS['LANG']->getLL('noEditAccessToAclRecord'), $id, $table));
        }
    }

    protected function getExtConf()
    {
        return GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('be_acl');
	}

    protected function errorResponse(ResponseInterface $response, $reason, $status = 500)
    {
        return $response->withStatus($status, $reason);
    }
}

