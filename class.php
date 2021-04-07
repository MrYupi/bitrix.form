<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
use \Bitrix\Main\Loader;
use \Bitrix\Main\Application;
/** @var array $arParams */
/** @var CMain $APPLICATION */
class IblockFormBitrix extends CBitrixComponent
{

    private $elemID = false;

    private function _checkModules()
    {
        if (!Loader::includeModule('iblock'))
        {
            throw new \Exception('Не загружены модули необходимые для работы модуля');
        }

        return true;
    }

    private function _app()
    {
        global $APPLICATION;
        return $APPLICATION;
    }

    private function _user()
    {
        global $USER;
        return $USER;
    }

    public function onPrepareComponentParams($arParams)
    {
        $this->arParams = $arParams;
        if(!intval($arParams["IBLOCK_ID"]))
        {
            throw new \Exception('Заданы неверные параметры');
        }
        return $arParams;
    }

    private function collectData()
    {
        $res = CIBlock::GetProperties(
            $this->arParams['IBLOCK_ID'],
            [
                'SORT' => 'ASC',
            ]
        );
        while($arFields = $res->GetNext())
        {
            $id = $arFields['ID'];
            $this->arResult['ITEMS'][$id] = [
                'CODE' => $arFields['CODE'],
                'NAME' => $arFields['NAME'],
                'REQUIRED' => $arFields['IS_REQUIRED'] == 'Y' || in_array($arFields['CODE'], $this->arParams['REQUIRED']) ? 'Y' : 'N',
                'HIDDEN' => in_array($arFields['CODE'], $this->arParams['HIDDEN']) ? 'Y' : 'N',
                'DEFAULT_VALUE' => array_key_exists($arFields['CODE'], $this->arParams['VALUE']) ? $this->arParams['VALUE'][$arFields['CODE']] : false,
                'VALIDATE' => array_key_exists($arFields['CODE'], $this->arParams['VALIDATE']) ? $this->arParams['VALIDATE'][$arFields['CODE']] : '',
            ];
            if($this->arResult['ITEMS'][$id]['HIDDEN'] == 'Y')
            {
                $this->arResult['ITEMS'][$id]['TYPE']  = 'hidden';
            }
            else
            {
                switch ($arFields['PROPERTY_TYPE'])
                {

                    case 'S':
                        if($arFields['USER_TYPE'] == 'HTML')
                        {
                            $this->arResult['ITEMS'][$id]['TYPE']  = 'textarea';
                        }
                        else
                        {
                            $this->arResult['ITEMS'][$id]['TYPE']  = 'text';
                        }
                        $this->arResult['ITEMS'][$id]['DB_TYPE'] = $arFields['PROPERTY_TYPE'];
                        break;
                    case 'L':
                        if($arFields['LIST_TYPE'] == 'C')
                        {
                            $this->arResult['ITEMS'][$id]['TYPE'] = 'checkbox';
                        }
                        else
                        {
                            $this->arResult['ITEMS'][$id]['TYPE'] = 'select';
                        }
                        $enumRes = CIBlockPropertyEnum::GetList(
                            [
                                'SORT' => 'ASC'
                            ],
                            [
                                'IBLOCK_ID' => $arFields['IBLOCK_ID'],
                                'PROPERTY_ID' => $arFields['ID']
                            ]
                        );
                        $this->arResult['ITEMS'][$id]['VALUE'] = [];
                        while($enumFields = $enumRes->GetNext())
                        {
                            $enumId = $enumFields['ID'];
                            $this->arResult['ITEMS'][$id]['VALUE'][$enumId] = $enumFields['VALUE'];

                        }
                        $this->arResult['ITEMS'][$id]['DB_TYPE'] = $arFields['PROPERTY_TYPE'];
                        break;
                    case 'E':
                        if($arFields['LINK_IBLOCK_ID'])
                        {
                            $this->arResult['ITEMS'][$id]['TYPE']  = 'list';
                            $this->arResult['ITEMS'][$id]['LIST_TYPE'] = $arFields['LIST_TYPE'];
                            $resList = CIBlockElement::GetList(
                                [
                                    'SORT' => 'ASC'
                                ],
                                [
                                    'IBLOCK_ID' => $arFields['LINK_IBLOCK_ID'],
                                    'ACTIVE' => 'Y'
                                ],
                                false,
                                false,
                                [
                                    'ID',
                                    'NAME'
                                ]
                            );
                            while ($arElem = $resList->GetNext())
                            {
                                $listId = $arElem['ID'];
                                $this->arResult['ITEMS'][$id]['VALUE'][$listId] = $arElem['NAME'];
                            }
                        }
                        $this->arResult['ITEMS'][$id]['DB_TYPE'] = $arFields['PROPERTY_TYPE'];
                        break;
                    case 'F':
                        $this->arResult['ITEMS'][$id]['MULTIPLE']  =  $arFields['MULTIPLE'];
                        $this->arResult['ITEMS'][$id]['TYPE']  = 'file';
                        $this->arResult['ITEMS'][$id]['DB_TYPE'] = $arFields['PROPERTY_TYPE'];
                        break;
                    default:
                }
            }
        }
        if($this->arParams['USE_PREVIEW_TEXT_AS_TEXTAREA'] == 'Y')
        {
            $this->arResult['ITEMS']['PREVIEW_TEXT'] = [
                'CODE' => 'PREVIEW_TEXT',
                'NAME' => 'Сообщение',
                'REQUIRED' => in_array('PREVIEW_TEXT', $this->arParams['REQUIRED']) ? 'Y' : 'N',
                'HIDDEN' => 'N',
                'DEFAULT_VALUE' => '',
                'TYPE' => 'textarea'
            ];
        }
    }


    private function submitForm()
    {
        $this->checkFields();
        if($this->arResult['STATUS'] != 'ERROR')
        {
            $this->writeElement();
            if($this->arParams['MAIL_EVENT'])
            {
                $this->sendEmail();
            }

            if($this->arResult['STATUS'] == 'SUCCESS')
            {
                $this->clearRequestFields();
            }

        }
    }

    public function executeComponent()
    {

        $this->_checkModules();
        $this->collectData();
        if($this->arParams['USE_CAPTCHA'] == 'Y')
        {
            $this->arResult["CAPTCHA_CODE"] = htmlspecialcharsbx($this->_app()->CaptchaGetCode());
        }

        if($this->request['submit'] == 'Y' && $this->request->getRequestMethod() == 'POST')
        {
            $this->submitForm();
            if($this->request->isAjaxRequest())
            {
                $this->arResult['DEBUG']['REQUEST'] = $_REQUEST;
                $this->arResult['DEBUG']['FILES'] = $_FILES;
                $this->_app()->RestartBuffer();
                echo json_encode($this->arResult);
                die();
            }
        }
        $this->includeComponentTemplate();


    }

    private function checkFields()
    {
        if(!check_bitrix_sessid())
        {
            $this->arResult['STATUS'] = 'ERROR';
            $this->arResult['ERROR'][] = 'Не пройдена проверка сессии.';
        }
        if($this->arParams['USE_CAPTCHA'] == 'Y')
        {
            if(!$this->_app()->CaptchaCheckCode($this->request["captcha_word"], $this->request["captcha_sid"]))
            {
                $this->arResult['STATUS'] = 'ERROR';
                $this->arResult['ERROR_FIELDS'][] = 'captcha_word';
                $this->arResult['ERROR'][] = 'Не верно введен защитный код';
            }
        }
        foreach ($this->arResult['ITEMS'] as &$item)
        {
            if($item['REQUIRED'] == 'Y' || in_array($item['CODE'], $this->arParams['REQUIRED']))
            {
                $error = false;
                switch ($item['DB_TYPE'])
                {
                    case 'F':
                        $file = $this->request->getFile($item['CODE']);
                        if(!$file['name'] || !$file['error'])
                        {
                            $error = true;
                        }
                        break;
                    default:
                        $error = !$this->request[$item['CODE']];
                }
                if($error)
                {
                    $this->arResult['STATUS'] = 'ERROR';
                    $this->arResult['ERROR'][] = 'Не заполнено обязателное поле ' . $item['CODE'];
                    $this->arResult['ERROR_FIELDS'][] = $item['CODE'];
                    $item['ERROR'] = true;
                }
                else
                {
                    //Если поле не пустое валидируем  значение
                    if (array_key_exists($item['CODE'], $this->arParams['VALIDATE'])) {
                        switch ($this->arParams['VALIDATE'][$item['CODE']]) {
                            case 'phone':
                                $phone = showNumbers($this->request[$item['CODE']]);
                                $pattern = '/^((8|\+7)[\- ]?)?(\(?\d{3}\)?[\- ]?)?[\d\- ]{7}$/';
                                if (!preg_match($pattern, $phone)) {
                                    $this->arResult['STATUS'] = 'ERROR';
                                    $this->arResult['ERROR'][] = 'Не пройдена валидация' . $item['CODE'];
                                    $this->arResult['ERROR_FIELDS'][] = $item['CODE'];

                                }
                                break;
                            case 'email':
                                $email = $this->request[$item['CODE']];
                                if (!check_email($email)) {
                                    $this->arResult['STATUS'] = 'ERROR';
                                    $this->arResult['ERROR'][] = 'Не пройдена валидация' . $item['CODE'];
                                    $this->arResult['ERROR_FIELDS'][] = $item['CODE'];
                                }
                                break;
                        }
                    }
                }
            }
            $item['REQUEST_VALUE'] = $this->request[$item['CODE']];
        }
        if($this->arParams['NEED_POLICY_CONFIRM'] == 'Y')
        {
            if(!$this->request['POLICY'])
            {
                $this->arResult['STATUS'] = 'ERROR';
                $this->arResult['ERROR_FIELDS'][] = 'POLICY';
                $this->arResult['ERROR'][] = 'Не согласен с политикой конфедициальности';
            }
        }
    }

    private function writeElement()
    {
        $arProp = [];
        $name = '';
        foreach ($this->arResult['ITEMS'] as $item)
        {
            switch ($item['DB_TYPE'])
            {

                case 'F':
                    //TODO Multiple
                    $file = $this->request->getFile($item['CODE']);
                    $arProp[$item['CODE']] = $file;
                    break;
                default:
                    if(in_array($item['CODE'], $this->arParams['FIELDS_GENERATE_NAME']))
                    {
                        if(strlen($name)) $name .= ' ';
                        $name .= $this->request[$item['CODE']];
                    }
                    $arProp[$item['CODE']] = $this->request[$item['CODE']];
            }


        }
        $el = new CIblockElement();
        $arFields = [
            'NAME' => $name ? $name : 'Заявка',
            'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
            'IBLOCK_SECTION_ID' => $this->arParams['SECTION_ID'],
            'PROPERTY_VALUES' => $arProp,
        ];
        if($this->arParams['USE_PREVIEW_TEXT_AS_TEXTAREA'] == 'Y')
        {
            $arFields['PREVIEW_TEXT'] = $this->request['PREVIEW_TEXT'];
        }
        $this->elemID = $el->Add($arFields);
        if(!$this->elemID)
        {
            $this->arResult['STATUS'] = 'ERROR';
            $this->arResult['ERROR'][] = $el->LAST_ERROR;
        }
        else
        {
            $this->arResult['STATUS'] = 'SUCCESS';
        }

    }

    private function sendEmail() {
        $arFields = [];
        $arFile = [];
        $arFields['ONE_STRING_MESSAGE'] = '';
        foreach ($this->arResult['ITEMS'] as $item)
        {

            switch ($item['DB_TYPE'])
            {
                case 'E':
                    $arFields['ONE_STRING_MESSAGE'] .= $item['NAME'] . ': ';
                    $arFields['ONE_STRING_MESSAGE'] .= $item['VALUE'][$this->request[$item['CODE']]] ? $item['VALUE'][$this->request[$item['CODE']]]  . "\n" :  'Не указано' . "\n";
                    $arFields[$item['CODE']] = $item['VALUE'][$this->request[$item['CODE']]] ? $item['VALUE'][$this->request[$item['CODE']]] :  'Не указано';
                    break;
                case 'L':
                    //Тут коммент что бы мне IDE не подчеркивало),свитч на вырост если че
                    $arFields['ONE_STRING_MESSAGE'] .= $item['NAME'] . ': ';
                    $arFields['ONE_STRING_MESSAGE'] .= $item['VALUE'][$this->request[$item['CODE']]] ? $item['VALUE'][$this->request[$item['CODE']]]  . "\n" :  'Не указано' . "\n";
                    $arFields[$item['CODE']] = $item['VALUE'][$this->request[$item['CODE']]] ? $item['VALUE'][$this->request[$item['CODE']]] :  'Не указано';
                    break;
                case 'F':
                    break;
                default:
                    $arFields['ONE_STRING_MESSAGE'] .= $item['NAME'].': ';
                    $arFields['ONE_STRING_MESSAGE'] .= $this->request[$item['CODE']] ?  $this->request[$item['CODE']]  . "\n" : 'Не указано' . "\n";
                    $arFields[$item['CODE']] = $this->request[$item['CODE']] ?  $this->request[$item['CODE']] : 'Не указано';
            }
        }
        $arFields['FORM_NAME'] = $this->arParams['FORM_NAME'];
        $messID = CEvent::Send($this->arParams['MAIL_EVENT'], SITE_ID, $arFields, $this->arParams['MAIL_DUPLICATE'], $this->arParams['MAIL_ID']);
        if(!$messID)
        {
            $this->arResult['ERROR'][] = 'Не удалось отправить письмо';
        }
    }

    private function clearRequestFields()
    {
        foreach ($this->arResult['ITEMS'] as &$item)
        {
            $item['REQUEST_VALUE'] = false;
        }
    }

    public function showInput($item, $data = [], $html = [])
    {
        $string = '';

        switch ($item['TYPE'])
        {
            case 'text':
                $string .= $html['before'];
                $string .= '<input name="' . $item['CODE'] . '"';
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                if($item['DEFAULT_VALUE'])
                {
                    $string .= 'value="' . $item['DEFAULT_VALUE'] . '"';
                }
                if($item['REQUEST_VALUE'])
                {
                    $string .= 'value="' . $item['REQUEST_VALUE'] . '"';
                }
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'type="text"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                $string .= ' />';
                $string .= $html['after'];
                break;
            case 'textarea':
                $string .= $html['before'];
                $string .= '<textarea name="' . $item['CODE'] . '"';
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                $string .= '>';
                if($item['DEFAULT_VALUE'])
                {
                    $string .= $item['DEFAULT_VALUE'];
                }
                if($item['REQUEST_VALUE'])
                {
                    $string .= $item['REQUEST_VALUE'];
                }
                $string .= '</textarea>';
                $string .= $html['after'];
                break;
            case 'list':
                $string .= $html['before'];
                $string .= '<select name="' . $item['CODE'] . '"';
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                $string .= '>';
                foreach ($item['VALUE'] as $keyValue => $nameValue)
                {
                    $checked = false;
                    if(($item['DEFAULT_VALUE'] == $keyValue && !$item['REQUEST_VALUE']) || $item['REQUEST_VALUE'] == $keyValue)
                    {
                        $checked = true;
                    }
                    $string .= '<option value="' . $keyValue . '" ' . ($checked ? 'selected' : '') . '>' . $nameValue . '</option>';
                }
                $string .= '</select>';
                $string .= $html['after'];
                break;
            case 'select':
                //Коммент для IDE
                $string .= $html['before'];
                $string .= '<select name="' . $item['CODE'] . '"';
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                $string .= '>';
                foreach ($item['VALUE'] as $keyValue => $nameValue)
                {
                    $checked = false;
                    if(($item['DEFAULT_VALUE'] == $keyValue && !$item['REQUEST_VALUE']) || $item['REQUEST_VALUE'] == $keyValue)
                    {
                        $checked = true;
                    }
                    $string .= '<option value="' . $keyValue . '" ' . ($checked ? 'selected' : '') . '>' . $nameValue . '</option>';
                }
                $string .= '</select>';
                $string .= $html['after'];
                break;
            case 'checkbox':
                $string .= $html['before'];
                foreach ($item['VALUE'] as $keyValue => $nameValue)
                {

                    $checked = false;
                    if(($item['DEFAULT_VALUE'] == $keyValue && !$item['REQUEST_VALUE']) || $item['REQUEST_VALUE'] == $keyValue)
                    {
                        $checked = true;
                    }
                    $string .= '<div class="radio__wrap">';
                    $string .= '<input type="radio" ' . ($checked ? 'checked' : '') . ' id="' . $item['CODE'] . '_' . $keyValue . '" name="' . $item['CODE'] . '"';

                    $string .= 'data-required="' . $item['REQUIRED'] . '"';
                    $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                    foreach ($data as $dKey => $dValue)
                    {
                        $string .= ' ' . $dKey . '="' . $dValue . '" ';
                    }
                    $string .= '>';

                    $string .= ' <label for="' . $item['CODE'] . '_' . $keyValue . '" class="radio">' . $nameValue . '</label>';
                    $string .= '</div>';
                }
                $string .= $html['after'];
                break;
            case 'file':
                $string .= $html['before'];
                $code = $item['CODE'];
                if($item['MULTIPLE'] == 'Y')
                {
                    $code .= '[]';
                }
                $string .= '<input name="' . $code . '"';
                if($item['MULTIPLE'] == 'Y')
                {
                    $string .= 'multiple';
                }
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'type="file"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                $string .= ' />';
                $string .= $html['after'];
                break;
            case 'hidden':
                $string .= '<input name="' . $item['CODE'] . '"';
                foreach ($data as $dKey => $dValue)
                {
                    $string .= ' ' . $dKey . '="' . $dValue . '" ';
                }
                if($item['DEFAULT_VALUE'])
                {
                    $string .= 'value="' . $item['DEFAULT_VALUE'] . '"';
                }
                $string .= 'data-required="' . $item['REQUIRED'] . '"';
                $string .= 'type="hidden"';
                $string .= 'data-validate="' . $item['VALIDATE'] . '"';
                $string .= ' />';
                break;
        }

        echo $string;
    }

    public static function debug($item)
    {
        echo '<pre style="background: black; padding: 10px; color: white">';
        print_r($item);
        echo '</pre>';
    }


}