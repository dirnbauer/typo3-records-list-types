<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Controller\ContentElement;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\ContentElement\ElementHistoryController as CoreElementHistoryController;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ElementHistoryController extends CoreElementHistoryController
{
    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssFile('EXT:records_list_types/Resources/Public/Css/history-overlay.css');
        $pageRenderer->addInlineLanguageLabelFile('EXT:records_list_types/Resources/Private/Language/locallang.xlf', 'historyOverlay.');
        $pageRenderer->loadJavaScriptModule('@webconsulting/records-list-types/HistoryOverlay.js');

        return parent::mainAction($request);
    }
}
