<?php
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
if (!$USER->IsAdmin()) {
    LocalRedirect('/');
}
\Bitrix\Main\Loader::includeModule('iblock');


// строка со схожестью > 60%

function getSimilarSting($arr, $str)
{
    if ($str == "") return null;
    $persArr = [];
    foreach ($arr as $str1) {
        similar_text($str1, $str, $pers);
        $persArr[] = $pers;
    }
    $max = max($persArr);
    $key = array_search($max, $persArr);
    if ($max < 60) return null; // когда 50% то Лесозаготовка определяет как Персонал для поля FIELD
    return $arr[$key];
}

// Формируем массив очищенных значений полей списка csv.

function prepareList($str)
{
    $str = trim($str);
    $str = str_replace('\n', '', $str);
    $str = str_replace(';', '', $str);
    $str = str_replace('.', '', $str);
    $arr = explode('•', $str);
    array_splice($arr, 0, 1);
    foreach ($arr as &$str) {
        $str = trim($str);
    }
    return $arr;
}


//по символьному коду инфоблока получаем его ID
$result = CIBlock::GetList([],
    ['CODE' => 'VACANCIES']);
$iblockIdVacancies = $result->Fetch()['ID'];
if (!$iblockIdVacancies) {
    die("Не найден инфоблок VACANCIES");
}

//удаляем элементы инфоблока вакансий
$result = CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockIdVacancies], false, false, ['ID']);
while ($element = $result->Fetch()) {
    CIBlockElement::Delete($element['ID']);
}


//считываем данные из csv в массив $vacancyData
$vacancyData = [];
$row = 1;
if (($handle = fopen("vacancy.csv", "r")) !== false) {
    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
        if ($row != 1) $vacancyData[] = $data;
        $row++;
    }
    fclose($handle);
}


//получаем значения свойств типа список.
$propValues = [];
$result = CIBlockPropertyEnum::GetList(
    ["SORT" => "ASC", "VALUE" => "ASC"],
    ['IBLOCK_ID' => $iblockIdVacancies]
);
while ($property = $result->Fetch()) {
    $key = trim($property['VALUE']);
    $propValues[$property['PROPERTY_CODE']][$key] = $property['ID'];
}


//создаём массив описания полей,свойств каждой вакансии для создания элементов инфоблоков
$elementsData = [];
foreach ($vacancyData as $vacancy) {
    $propElement = [];

    $propElement['ACTIVITY'] = $propValues['ACTIVITY'][getSimilarSting(array_keys($propValues['ACTIVITY']), $vacancy[9])];
    $propElement['FIELD'] = $propValues['FIELD'][getSimilarSting(array_keys($propValues['FIELD']), $vacancy[11])];
    $propElement['OFFICE'] = $propValues['OFFICE'][getSimilarSting(array_keys($propValues['OFFICE']), $vacancy[1])];
    $propElement['LOCATION'] = $propValues['LOCATION'][getSimilarSting(array_keys($propValues['LOCATION']), $vacancy[2])];
    $propElement['TYPE'] = $propValues['TYPE'][getSimilarSting(array_keys($propValues['TYPE']), $vacancy[8])];
    $propElement['DATE'] = date('d.m.Y');
    $propElement['REQUIRE'] = prepareList($vacancy[4]);
    $propElement['DUTY'] = prepareList($vacancy[5]);
    $propElement['CONDITIONS'] = prepareList($vacancy[6]);
    $propElement['EMAIL'] = $vacancy[12];
    $propElement['SCHEDULE'] = $propValues['SCHEDULE'][getSimilarSting(array_keys($propValues['SCHEDULE']), $vacancy[10])];
    $propElement['SALARY_VALUE'] = ($vacancy[7]);


    if ($propElement['SALARY_VALUE'] == '-') {
        $propElement['SALARY_VALUE'] = '';
    } elseif ($propElement['SALARY_VALUE'] == 'по договоренности') {
        $propElement['SALARY_VALUE'] = '';
        $propElement['SALARY_TYPE'] = $propValues['SALARY_TYPE']['договорная'];
    } else {
        $arSalary = explode(' ', $propElement['SALARY_VALUE']);
        if ($arSalary[0] == 'от' || $arSalary[0] == 'до') {
            $propElement['SALARY_TYPE'] = $propElement['SALARY_TYPE'][$arSalary[0]];
            array_splice($arSalary, 0, 1);
            $propElement['SALARY_VALUE'] = implode(' ', $arSalary);
        } else {
            $propElement['SALARY_TYPE'] = $propValues['SALARY_TYPE']['='];
        }
    }


    $arFieds = [
        "MODIFIED_BY" => $USER->GetID(),
        "IBLOCK_SECTION_ID" => false,
        "IBLOCK_ID" => $iblockIdVacancies,
        "PROPERTY_VALUES" => $propElement,
        "NAME" => $vacancy[3],
        "ACTIVE" => end($vacancy) ? 'Y' : 'N',
    ];
    $elementsData[] = $arFieds;
}


//добавление элементов инфоблока вакансий
foreach ($elementsData as $elementData) {
    $el = new CIBlockElement();
    if ($PRODUCT_ID = $el->Add($elementData)) {
        echo "Добавлен элемент с ID : " . $PRODUCT_ID . "<br>";
    } else {
        echo "Error: " . $el->LAST_ERROR . '<br>';
    }
}


