<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

\CModule::includeModule("iblock");

function copyIblock($oldIblockId, $newTypeId, $newFields = []) {
    $iblock = \CIBlock::GetByID($oldIblockId)->fetch();
    $iblock['FIELDS'] = \CIBlock::GetFields($iblock['ID']);
    $iblock['GROUP_ID'] = \CIBlock::GetGroupPermissions($iblock['ID']);
    $iblock['IBLOCK_TYPE_ID'] = $newTypeId;
    unset($iblock['ID']);
    unset($iblock['TIMESTAMP_X']);
    unset($iblock['LANG_DIR']);
    unset($iblock['SERVER_NAME']);

    $iblock['NAME'] = "EN. ". $iblock['NAME'];

    $ib = new \CIBlock;
    $fields = array_merge($iblock, $newFields);
    $newIblockId = $ib->Add($fields);
    if (!$newIblockId) {
        throw new \Exception($ib->LAST_ERROR);
    }

    copyIblockProperties($oldIblockId, $newIblockId);
    $old2newSections = copyIblockSections($oldIblockId, $newIblockId);
    copyIblockElements($oldIblockId, $newIblockId, $old2newSections);
}

function copyIblockProperties($oldIblockId, $newIblockId) {
    $result = \CIBlockProperty::getList([], ['IBLOCK_ID' => $oldIblockId]);
    while ($row = $result->fetch()) {
        copyProperty($row, $newIblockId);
    }
}

function copyProperty($oldProperty, $newIblockId) {
    $values = [];
    $result = \CIBlockProperty::GetPropertyEnum($oldProperty['ID']);
    while ($row = $result->fetch()) {
        unset($row['ID']);
        unset($row['PROPERTY_ID']);
        $values[] = $row;
    }

    $oldProperty['VALUES'] = $values;
    $oldProperty['IBLOCK_ID'] = $newIblockId;

    unset($oldProperty['ID']);
    unset($oldProperty['TIMESTAMP_X']);
    unset($oldProperty['LINK_IBLOCK_ID']);

    $ibp = new \CIBlockProperty;
    $id = $ibp->Add($oldProperty);
    if ($ibp->LAST_ERROR) {
        throw new \Exception($ibp->LAST_ERROR);
    }
    return $id;
}

function copyIblockSections($oldIblockId, $newIblockId) {
    $old2new = [];
    $result = \CIBlockSection::getList([], ['IBLOCK_ID' => $oldIblockId]);
    while ($row = $result->fetch()) {
        $oldId = $row['ID'];
        $newId = copySection($row, $newIblockId);
        $old2new[$oldId] = $newId;
    }

    $result = \CIBlockSection::getList([], ['IBLOCK_ID' => $oldIblockId]);
    while ($row = $result->fetch()) {
        $oldId = $row['ID'];
        $parentOldId = $row['IBLOCK_SECTION_ID'];
        if (!$parentOldId) {
            continue;
        }

        $newId = $old2new[$oldId];
        $parentNewId = $old2new[$parentOldId];

        $ibs = new \CIBlockSection;
        $ibs->Update($newId, [
            'IBLOCK_SECTION_ID' => $parentNewId,
        ]);
        if ($ibs->LAST_ERROR) {
            echo $ibs->LAST_ERROR . "<BR>";
            // throw new \Exception($ibs->LAST_ERROR);
        }
    }
    return $old2new;
}

function copySection($oldSection, $newIblockId) {
    $oldSectionId = $oldSection['ID'];
    $oldSection['IBLOCK_ID'] = $newIblockId;

    unset($oldSection['ID']);
    unset($oldSection['IBLOCK_SECTION_ID']);
    unset($oldSection['DATE_CREATE']);
    unset($oldSection['IBLOCK_TYPE_ID']);
    unset($oldSection['IBLOCK_CODE']);
    unset($oldSection['IBLOCK_EXTERNAL_ID']);
    unset($oldSection['TIMESTAMP_X']);
    unset($oldSection['LINK_IBLOCK_ID']);

    $ibs = new \CIBlockSection;
    $id = $ibs->Add($oldSection);
    if ($ibs->LAST_ERROR) {
        echo "Раздел {$oldSectionId}: ". $ibs->LAST_ERROR . "<BR>";
        // throw new \Exception($ibs->LAST_ERROR);
    }
    return $id;
}

function getOld2newPropertyListValues($oldIblockId, $newIblockId) {
    $ret = [];
    $newProps = \Bitrix\Iblock\PropertyEnumerationTable::getList([
        'select' => [
            'ID',
            'VALUE',
            'PROPERTY.CODE',
        ],
        'filter' => [
            'PROPERTY.IBLOCK_ID' => $newIblockId,
        ],
    ])->fetchAll();
    foreach ($newProps as $prop) {
        $value = $prop['VALUE'];
        $code = $prop['IBLOCK_PROPERTY_ENUMERATION_PROPERTY_CODE'];
        if (!isset($ret[$code])) {
            $ret[$code] = [];
        }
        $ret[$code][$value] = $prop['ID'];
    }
    return $ret;
}

function copyIblockElements($oldIblockId, $newIblockId, $old2newSections) {
    $result = \CIBlockElement::getList([], ['IBLOCK_ID' => $oldIblockId]);
    $old2newPropertyListValues = getOld2newPropertyListValues($oldIblockId, $newIblockId);
    while ($row = $result->fetch()) {
        copyElement($row, $newIblockId, $old2newSections, $old2newPropertyListValues);
    }
}

function copyElement($oldElement, $newIblockId, $old2newSections, $old2newPropertyListValues) {
    $oldElementId = $oldElement['ID'];

    if ($oldElement['PREVIEW_PICTURE']) {
        $oldElement['PREVIEW_PICTURE'] = \CFile::makeFileArray(
            \CFile::getPath($oldElement['PREVIEW_PICTURE'])
        );
    }

    if ($oldElement['DETAIL_PICTURE']) {
        $oldElement['DETAIL_PICTURE'] = \CFile::makeFileArray(
            \CFile::getPath($oldElement['DETAIL_PICTURE'])
        );
    }

    $oldElement['PROPERTY_VALUES'] = [];
    $result = \CIBlockElement::getProperty($oldElement['IBLOCK_ID'], $oldElement['ID']);
    while ($row = $result->fetch()) {
        $code = $row["CODE"];
        $value = $row['VALUE'];
        $isMultiple = $row['MULTIPLE'] === 'Y';

        if ($row['PROPERTY_TYPE'] === 'L') {
            $value = $old2newPropertyListValues[$code][$value] ?? null;
        }
        else if ($row['PROPERTY_TYPE'] === 'F') {
            $file = \CFile::makeFileArray(
                \CFile::getPath($value)
            );
            $value = [
                'VALUE' => $file,
                'DESCRIPTION' => $file['name'],
            ];
        }

        if (!isset($oldElement['PROPERTY_VALUES'][$code])) {
            if ($isMultiple) {
                $oldElement['PROPERTY_VALUES'][$code] = [];
            }
            else {
                $oldElement['PROPERTY_VALUES'][$code] = null;
            }
        }

        if ($isMultiple) {
            $oldElement['PROPERTY_VALUES'][$code][] = $value;
        }
        else {
            $oldElement['PROPERTY_VALUES'][$code] = $value;
        }
    }

    if ($oldElement['IBLOCK_SECTION_ID']) {
        $oldElement['IBLOCK_SECTION_ID'] = $old2newSections[$oldElement['IBLOCK_SECTION_ID']];
    }

    $oldElement['IBLOCK_ID'] = $newIblockId;
    unset($oldElement['ID']);
    unset($oldElement['TIMESTAMP_X_UNIX']);
    unset($oldElement['DATE_CREATE_UNIX']);
    unset($oldElement['SHOW_COUNTER']);
    unset($oldElement['SHOW_COUNTER_START']);
    unset($oldElement['SHOW_COUNTER_START_X']);
    unset($oldElement['USER_NAME']);
    unset($oldElement['LOCKED_USER_NAME']);
    unset($oldElement['CREATED_USER_NAME']);
    unset($oldElement['LANG_DIR']);
    unset($oldElement['LID']);
    unset($oldElement['DATE_CREATE']);
    unset($oldElement['CREATED_DATE']);
    unset($oldElement['IBLOCK_TYPE_ID']);
    unset($oldElement['IBLOCK_CODE']);
    unset($oldElement['IBLOCK_NAME']);
    unset($oldElement['IBLOCK_EXTERNAL_ID']);
    unset($oldElement['TIMESTAMP_X']);
    unset($oldElement['LINK_IBLOCK_ID']);

    $ibe = new \CIBlockElement;
    $id = $ibe->Add($oldElement);
    if ($ibe->LAST_ERROR) {
        echo "Элемент {$oldElementId}: ". $ibe->LAST_ERROR . "<BR>";
        // throw new \Exception($ibe->LAST_ERROR);
    }
    return $id;
}


set_time_limit(120);

$newTypeId = "en";
$iblockIds = [];

$result = \CIBlock::getList([], ['IBLOCK_TYPE_ID' => 'ru']);
while ($row = $result->fetch()) {
    if ($row['ID'] == 5) {
        continue;
    }
    $iblockIds[] = $row['ID'];
}

foreach ($iblockIds as $iblockId) {
    copyIblock($iblockId, $newTypeId, [
        'LID' => 's2',
        'SITE_ID' => 's2',
    ]);
}

echo "end";
