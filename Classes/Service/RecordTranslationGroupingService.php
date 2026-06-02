<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\RecordsListTypes\Utility\ArrayUtility;

/**
 * Groups connected translations onto default-language records for alternative views.
 */
final readonly class RecordTranslationGroupingService implements SingletonInterface
{
    public function __construct(
        private RecordViewEnrichmentService $viewEnrichmentService,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    public function groupTranslationsOnRecords(
        array $records,
        string $tableName,
        int $pageId,
        RecordGridDataProvider $dataProvider,
        RecordViewEnrichmentContext $context,
    ): array {
        if (!$dataProvider->isLanguageAwareTable($tableName)) {
            return $records;
        }

        $langFields = $dataProvider->getLanguageFields($tableName);
        $languageField = $langFields['languageField'];
        $transOrigPointerField = $langFields['transOrigPointerField'];

        if ($languageField === '' || $transOrigPointerField === '') {
            return $records;
        }

        $defaultRecords = [];
        $freeTranslations = [];

        foreach ($records as $record) {
            $rawRecord = is_array($record['rawRecord'] ?? null) ? $record['rawRecord'] : [];
            $langUidRaw = $rawRecord[$languageField] ?? 0;
            $langUid = is_numeric($langUidRaw) ? (int) $langUidRaw : 0;
            $parentPointerRaw = $rawRecord[$transOrigPointerField] ?? 0;
            $parentPointer = is_numeric($parentPointerRaw) ? (int) $parentPointerRaw : 0;

            if ($langUid === 0 || $langUid === -1) {
                $record['translations'] = [];
                $record['isFreeTranslation'] = false;
                $defaultRecords[] = $record;
            } elseif ($parentPointer === 0) {
                $record['isFreeTranslation'] = true;
                $record['translations'] = [];
                $freeTranslations[] = $record;
            }
        }

        $parentUids = array_map(
            static function (array $r): int {
                $uidRaw = $r['uid'] ?? 0;

                return is_numeric($uidRaw) ? (int) $uidRaw : 0;
            },
            $defaultRecords,
        );
        $parentUids = array_values(array_filter($parentUids, static fn(int $uid): bool => $uid > 0));

        $translatedByParent = [];
        if ($parentUids !== []) {
            $translationsGrouped = $dataProvider->getTranslationsForRecords($tableName, $pageId, $parentUids);
            $translatedByParent = $this->enrichTranslationsWithLanguage(
                $translationsGrouped,
                $tableName,
                $languageField,
                $context,
            );
        }

        $translationLanguages = $this->getTranslationLanguages($tableName, $context);

        foreach ($defaultRecords as &$record) {
            $uidRaw = $record['uid'] ?? 0;
            $uid = is_numeric($uidRaw) ? (int) $uidRaw : 0;
            $translated = $translatedByParent[$uid] ?? [];
            $record['translations'] = $this->buildTranslationSlots(
                $tableName,
                $uid,
                $translated,
                $translationLanguages,
            );
            $record['translatedCount'] = count(array_filter(
                $record['translations'],
                static fn(array $slot): bool => ($slot['state'] ?? '') === 'translated',
            ));
            $record['untranslatedCount'] = count($record['translations']) - $record['translatedCount'];
        }
        unset($record);

        return array_merge($defaultRecords, $freeTranslations);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $translationsGrouped
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function enrichTranslationsWithLanguage(
        array $translationsGrouped,
        string $tableName,
        string $languageField,
        RecordViewEnrichmentContext $context,
    ): array {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $siteLanguages = $context->pageContext->site->getAvailableLanguages(
            $backendUser,
            false,
            $context->pageContext->pageId,
        );
        $enriched = [];

        foreach ($translationsGrouped as $parentUid => $translations) {
            $perLang = [];
            foreach ($translations as $translation) {
                $rawRecord = is_array($translation['rawRecord'] ?? null) ? $translation['rawRecord'] : [];
                $langUidRaw = $rawRecord[$languageField] ?? 0;
                $langUid = is_numeric($langUidRaw) ? (int) $langUidRaw : 0;

                $translation['sysLanguageUid'] = $langUid;
                $translation['languageFlagIdentifier'] = '';
                $translation['languageTitle'] = '';

                foreach ($siteLanguages as $siteLanguage) {
                    if ($siteLanguage->getLanguageId() === $langUid) {
                        $translation['languageFlagIdentifier'] = $siteLanguage->getFlagIdentifier();
                        $translation['languageTitle'] = $siteLanguage->getTitle();
                        break;
                    }
                }

                $translation = $this->viewEnrichmentService->enrichRecordWithEditUrls($translation, $context);
                $translation['title'] = BackendUtility::getRecordTitle($tableName, $rawRecord, false, true);
                $translation['permissions'] = $this->viewEnrichmentService->computeRecordPermissions(
                    $tableName,
                    ArrayUtility::stringKeyArray($rawRecord),
                    $context,
                );
                $perLang[$langUid] = $translation;
            }
            $enriched[(int) $parentUid] = $perLang;
        }

        return $enriched;
    }

    /**
     * @return array<int, array{id:int, title:string, flagIdentifier:string}>
     */
    private function getTranslationLanguages(string $tableName, RecordViewEnrichmentContext $context): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $siteLanguages = $context->pageContext->site->getAvailableLanguages(
            $backendUser,
            false,
            $context->pageContext->pageId,
        );
        $selectedLanguageIds = $context->pageContext->selectedLanguageIds;

        $languages = [];
        foreach ($siteLanguages as $siteLanguage) {
            $languageId = $siteLanguage->getLanguageId();
            if ($languageId <= 0) {
                continue;
            }
            if (!$backendUser->checkLanguageAccess($languageId)) {
                continue;
            }
            if ($selectedLanguageIds !== [] && !in_array($languageId, $selectedLanguageIds, true)) {
                continue;
            }
            $languages[$languageId] = [
                'id' => $languageId,
                'title' => $siteLanguage->getTitle(),
                'flagIdentifier' => $siteLanguage->getFlagIdentifier(),
            ];
        }

        ksort($languages);

        return $languages;
    }

    /**
     * @param array<int, array<string, mixed>> $translationsByLanguage
     * @param array<int, array{id:int, title:string, flagIdentifier:string}> $languages
     * @return array<int, array<string, mixed>>
     */
    private function buildTranslationSlots(
        string $tableName,
        int $parentUid,
        array $translationsByLanguage,
        array $languages,
    ): array {
        $backendUser = $this->getBackendUser();
        $slots = [];
        foreach ($languages as $language) {
            $languageId = $language['id'];
            if (isset($translationsByLanguage[$languageId])) {
                $translation = $translationsByLanguage[$languageId];
                $translation['state'] = 'translated';
                $translation['languageId'] = $languageId;
                if (($translation['languageFlagIdentifier'] ?? '') === '') {
                    $translation['languageFlagIdentifier'] = $language['flagIdentifier'];
                }
                if (($translation['languageTitle'] ?? '') === '') {
                    $translation['languageTitle'] = $language['title'];
                }
                $slots[] = $translation;
                continue;
            }

            $slots[] = [
                'state' => 'untranslated',
                'languageId' => $languageId,
                'languageTitle' => $language['title'],
                'languageFlagIdentifier' => $language['flagIdentifier'],
                'parentTable' => $tableName,
                'parentUid' => $parentUid,
                'permissions' => [
                    'canEdit' => false,
                    'canDelete' => false,
                    'canToggleVisibility' => false,
                    'canLocalize' => $backendUser instanceof BackendUserAuthentication
                        && $backendUser->checkLanguageAccess($languageId)
                        && $backendUser->check('tables_modify', $tableName),
                ],
            ];
        }

        return $slots;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? $user : null;
    }
}
