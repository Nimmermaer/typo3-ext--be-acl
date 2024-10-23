<?php

declare(strict_types=1);

namespace JBartels\BeAcl\Controller;

use Doctrine\DBAL\Exception;
use JBartels\BeAcl\Exception\RuntimeException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Beuser\Controller\PermissionController as CorePermissionController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\HtmlSanitizer\Context;

/**
 * Backend ACL - Replacement for "web->Access"
 */
class PermissionController extends CorePermissionController
{
    private const SESSION_PREFIX = 'tx_Beuser_';

    private const DEPTH_LEVELS = [1, 2, 3, 4, 10];

    private const RECURSIVE_LEVELS = 10;

    private const ALLOWED_ACTIONS = ['index', 'edit', 'update'];

    protected array $aclList = [];

    protected string $currentAction;

    protected array $aclTypes = [0, 1];

    protected string $table = 'tx_beacl_acl';

    protected int $id = 0;

    protected int $depth;
    protected TcaSchemaFactory $tcaSchemaFactory;

    protected array $pageInfo = [];
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        PageRenderer $pageRenderer,
        IconFactory $iconFactory,
        UriBuilder $uriBuilder,
        ResponseFactoryInterface $responseFactory,
        BackendViewFactory $backendViewFactory,
    )
    {
        $this->tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        parent::__construct($moduleTemplateFactory, $pageRenderer, $iconFactory, $uriBuilder, $responseFactory, $backendViewFactory);
    }

    public function indexAction(ModuleTemplate $view, ServerRequestInterface $request): ResponseInterface
    {
        $view->assignMultiple([
            'currentId' => $this->id,
            'viewTree' => $this->getTree(),
            'beUsers' => BackendUtility::getUserNames(),
            'beGroups' => BackendUtility::getGroupNames(),
            'depth' => $this->depth,
            'depthOptions' => $this->getDepthOptions(),
            'depthBaseUrl' => $this->uriBuilder->buildUriFromRoute('permissions_pages', [
                'id' => $this->id,
                'depth' => '${value}',
                'action' => 'index',
            ]),
            'editUrl' => $this->uriBuilder->buildUriFromRoute('permissions_pages', [
                'action' => 'edit',
            ]),
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('permissions_pages', [
                'id' => $this->id,
                'depth' => $this->depth,
                'action' => 'index',
            ]),
        ]);

        // Get ACL configuration
        $beAclConfig = $this->getExtConf();

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;
        $enableFilterSelector = $beAclConfig['enableFilterSelector'] ? 1 : 0;

        $view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);
        $view->assign('enableFilterSelector', $enableFilterSelector);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        /*
         *  User ACLs
         */
        $userAcls = $this->aclObjects(0, $beAclConfig, $request);
        // If filter is enabled, filter user ACLs according to selection
        if ($enableFilterSelector) {
            $usersSelected = array_filter($userAcls, fn($var) => !empty($var['selected']));
        } // No filter enabled, so show all user ACLs
        else {
            $usersSelected = $userAcls;
        }
        $view->assign('userSelectedAcls', $usersSelected);

        // Options for user filter
        $view->assign('userFilterOptions', [
            'options' => $userAcls,
            'title' => $GLOBALS['LANG']->sl('aclUsers'),
            'id' => 'userAclFilter',
        ]);

        /*
         *  Group ACLs
         */
        $groupAcls = $this->aclObjects(1, $beAclConfig, $request);

        // If filter is enabled, filter group ACLs according to selection
        if ($enableFilterSelector) {
            $groupsSelected = array_filter($groupAcls, fn($var) => !empty($var['selected']));
        } // No filter enabled, so show all group ACLs
        else {
            $groupsSelected = $groupAcls;
        }

        $view->assign('groupSelectedAcls', $groupsSelected);

        // Options for group filter
        $view->assign('groupFilterOptions', [
            'options' => $groupAcls,
            'title' => $GLOBALS['LANG']->sl('aclGroups'),
            'id' => 'groupAclFilter',
        ]);

        /*
         *  ACL Tree
         */
        $this->buildACLTree(array_keys($userAcls), array_keys($groupAcls));
        $view->assign('aclList', $this->aclList);
        return $view->renderResponse('Permission/Index');
    }

    public function editAction(ModuleTemplate $view, ServerRequestInterface $request): ResponseInterface
    {

        $lang = $this->getLanguageService();
        $selectNone = $lang->sL('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectNone');
        $selectUnchanged = $lang->sL('LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:selectUnchanged');

        // Owner selector
        $beUserDataArray = [
            0 => $selectNone,
        ];
        foreach (BackendUtility::getUserNames() as $uid => $row) {
            $beUserDataArray[$uid] = $row['username'] ?? '';
        }
        $beUserDataArray[-1] = $selectUnchanged;

        // Group selector
        $beGroupDataArray = [
            0 => $selectNone,
        ];
        foreach (BackendUtility::getGroupNames() as $uid => $row) {
            $beGroupDataArray[$uid] = $row['title'] ?? '';
        }
        $beGroupDataArray[-1] = $selectUnchanged;

        $view->assignMultiple([
            'id' => $this->id,
            'depth' => $this->depth,
            'currentBeUser' => $this->pageInfo['perms_userid'] ?? 0,
            'beUserData' => $beUserDataArray,
            'currentBeGroup' => $this->pageInfo['perms_groupid'] ?? 0,
            'beGroupData' => $beGroupDataArray,
            'pageInfo' => $this->pageInfo,
            'returnUrl' => $this->returnUrl,
            'recursiveSelectOptions' => $this->getRecursiveSelectOptions(),
            'formAction' => (string)$this->uriBuilder->buildUriFromRoute('permissions_pages', [
                'action' => 'update',
                'id' => $this->id,
                'depth' => $this->depth,
                'returnUrl' => $this->returnUrl,
            ]),
        ]);

        // Get ACL configuration
        $beAclConfig = $this->getExtConf();

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;

        $view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        // ACL CODE
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
        $statement = $queryBuilder
            ->select('*')
            ->from('tx_beacl_acl')->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($this->id, Connection::PARAM_INT)))->executeQuery();
        $pageAcls = [];

        while ($result = $statement->fetchAssociative()) {
            $pageAcls[] = $result;
        }

        $userGroupSelectorOptions = [];
        foreach ([
                     1 => 'Group',
                     0 => 'User',
                 ] as $type => $label) {
            $option = new \stdClass();
            $option->key = $type;
            $option->value = LocalizationUtility::translate(
                'LLL:EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf:acl' . $label,
                'be_acl'
            );
            $userGroupSelectorOptions[] = $option;
        }
        $view->assign('userGroupSelectorOptions', $userGroupSelectorOptions);
        $view->assign('pageAcls', $pageAcls);

        return $view->renderResponse('Permission/Edit');
    }

    protected function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array)($request->getParsedBody()['data'] ?? []);
        $mirror = (array)($request->getParsedBody()['mirror'] ?? []);
        // Process data map
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        return parent::updateAction($request);
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
     * @throws Exception
     * @global array $BE_USER
     */
    protected function aclObjects(int $type, array $conf, ServerRequestInterface $request): array
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
                $queryBuilder->expr()->eq('type',
                    $queryBuilder->createNamedParameter($type, Connection::PARAM_INT)
                )
            )->executeQuery();
        // Process results

        while ($result = $statement->fetchAssociative()) {
            $aclObjects[$result['object_id']] = $result;
        }

        // Check results
        if (empty($aclObjects)) {
            return $aclObjects;
        }

        // If filter selector is enabled, then determine currently selected items
        if ($conf['enableFilterSelector']) {
            // get current selection from UC, merge data, write it back to UC
            $currentSelection = $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] ?? [];

            $currentSelectionOverride_raw = $request->getQueryParams()['tx_beacl_objsel'] ?? '';
            $currentSelectionOverride = [];
            if (is_array($currentSelectionOverride_raw) && array_key_exists($type, $currentSelectionOverride_raw) && is_array($currentSelectionOverride_raw[$type])) {
                foreach ($currentSelectionOverride_raw[$type] as $tmp) {
                    $currentSelectionOverride[$tmp] = $tmp;
                }
            }
            if ($currentSelectionOverride) {
                $currentSelection = $currentSelectionOverride;
            }

            $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] = $currentSelection;
            $BE_USER->writeUC();
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
    protected function buildACLTree(array $users, array $groups): void
    {
        $startPerms = [
            0 => [],
            1 => [],
        ];

        // get permissions in the starting point for users and groups
        $rootLine = BackendUtility::BEgetRootLine($this->id);
        $currentPage = array_shift($rootLine); // needed as a starting point

        // Iterate rootline, looking for recursive ACLs that may apply to the current page
        foreach ($rootLine as $level => $values) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
            $statement = $queryBuilder
                ->select('uid', 'pid', 'type', 'object_id', 'permissions', 'recursive')
                ->from('tx_beacl_acl')->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($values['uid'], Connection::PARAM_INT)), $queryBuilder->expr()->eq('recursive', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))->executeQuery();
            while ($result = $statement->fetchAssociative()) {
                // User type ACLs
                if ($result['type'] == 0
                    && in_array($result['object_id'], $users)
                    && !array_key_exists($result['object_id'], $startPerms[0])
                ) {
                    $startPerms[0][$result['object_id']] = [
                        'uid' => $result['uid'],
                        'permissions' => $result['permissions'],
                        'recursive' => $result['recursive'],
                        'pid' => $result['pid'],
                    ];
                } // Group type ACLs
                elseif ($result['type'] == 1
                    && in_array($result['object_id'], $groups)
                    && !array_key_exists($result['object_id'], $startPerms[1])
                ) {
                    $startPerms[1][$result['object_id']] = [
                        'uid' => $result['uid'],
                        'permissions' => $result['permissions'],
                        'recursive' => $result['recursive'],
                        'pid' => $result['pid'],
                    ];
                }
            }
        }

        $this->traversePageTree_acl($startPerms, $currentPage['uid']);
    }

    protected function getDefaultAclMetaData(): array
    {
        return array_fill_keys($this->aclTypes, [
            'acls' => 0,
            'inherited' => 0,
        ]);
    }

    /**
     * Adds count meta data to the page ACL list
     */
    protected function addAclMetaData(array &$pageData): void
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
     * @throws Exception
     */
    protected function traversePageTree_acl(array $parentACLs, int $pageId): void
    {
        // Fetch ACLs aasigned to given page
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_beacl_acl');
        $statement = $queryBuilder
            ->select('uid', 'pid', 'type', 'object_id', 'permissions', 'recursive')
            ->from('tx_beacl_acl')->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))->executeQuery();

        $hasNoRecursive = [];
        $this->aclList[$pageId] = $parentACLs;

        $this->addAclMetaData($this->aclList[$pageId]);

        while ($result = $statement->fetchAssociative()) {
            $aclData = [
                'uid' => $result['uid'],
                'permissions' => $result['permissions'],
                'recursive' => $result['recursive'],
                'pid' => $result['pid'],
            ];

            // Non-recursive ACL
            if ($result['recursive'] == 0) {
                $this->aclList[$pageId][$result['type']][$result['object_id']] = $aclData;
                $hasNoRecursive[$pageId][$result['type']][$result['object_id']] = $aclData;
            } else {
                // Recursive ACL
                // Add to parent ACLs for sub-pages
                $parentACLs[$result['type']][$result['object_id']] = $aclData;
                // If there also is a non-recursive ACL for this object_id, that takes precedence
                // for this page. Otherwise, add it to the ACL list.
                if ($hasNoRecursive[$pageId][$result['type']][$result['object_id']] ?? false) {
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
            ->from('pages')->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))->executeQuery();
        while ($result = $statement->fetchAssociative()) {
            $this->traversePageTree_acl($parentACLs, $result['uid']);
        }
    }

    /**
     * @throws \JsonException
     */
    public function handleAjaxRequest(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $action = $parsedBody['action'] ?? null;


        if ($action == 'delete_acl') {
            $response = $this->deleteAcl($request);
        } else {
            $response = parent::handleAjaxRequest($request);
        }

        return $response;
    }

    protected function deleteAcl(ServerRequestInterface $request): ResponseInterface
    {
        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');
        $GLOBALS['LANG']->sl('aclUsers');

        $postData = $request->getParsedBody();
        $aclUid = !empty($postData['acl']) ? $postData['acl'] : null;

        if (!MathUtility::canBeInterpretedAsInteger($aclUid)) {
            return $this->htmlResponse($GLOBALS['LANG']->sl('noAclId'), 400);
        }
        $aclUid = (int)$aclUid;
        // Prepare command map
        $cmdMap = [
            $this->table => [
                $aclUid => [
                    'delete' => 1,
                ],
            ],
        ];

        try {
            // Process command map
            /** @var DataHandler $dataHandler */
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmdMap);
            $this->checkModifyAccess($this->table, $aclUid, $dataHandler);
            $dataHandler->process_cmdmap();
        } catch (\Exception $ex) {
            return $this->htmlResponse($ex->getMessage(), 403);
        }

        $body = [
            'title' => $GLOBALS['LANG']->sl('aclSuccess'),
            'message' => $GLOBALS['LANG']->sl('aclDeleted'),
        ];
        // Return result
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $response;
    }

    protected function checkModifyAccess($table, $id, DataHandler $dataHandler): void
    {
        // Check modify access
        $modifyAccessList = $dataHandler->checkModifyAccessList($table);
        // Check basic permissions and circumstances:

        if (!$this->tcaSchemaFactory->has($table) || $this->tcaSchemaFactory->get($table)->hasCapability(TcaSchemaCapability::AccessReadOnly) || !$modifyAccessList) {
            throw new RuntimeException($GLOBALS['LANG']->sl('noPermissionToModifyAcl'));
        }

        // Check table / id
        if (!$GLOBALS['TCA'][$table] || !$id) {
            throw new RuntimeException(sprintf($GLOBALS['LANG']->sl('noEditAccessToAclRecord'), $id, $table));
        }

        // Check edit access
        $hasEditAccess = $dataHandler->BE_USER->recordEditAccessInternals($table, $id, false, false, true);
        if (!$hasEditAccess) {
            throw new RuntimeException(sprintf($GLOBALS['LANG']->sl('noEditAccessToAclRecord'), $id, $table));
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
