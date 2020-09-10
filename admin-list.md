# AdminList

Обертка для работы со стандартным список элементов в админке.

Класс:
```php
class AdminList
{
    protected $id;
    protected $list;
    protected $result;
    protected $headers;
    protected $sorting;
    protected $primaryField = 'ID';

    protected $activeOrder;
    protected $activeFilter;
    protected $filterFields;

    protected $navResultCallback;
    protected $rowRendererCallback;

    public function __construct(string $id, array $params = [])
    {
        $this->id = $id;
        $this->setParams($params);
    }

    public function getList()
    {
        if ($this->list === null) {
            $this->list = new \CAdminList($this->id, $this->sorting);
        }
        return $this->list;
    }

    public function setParams(array $params)
    {
        /**
         * в формате ['ID' => 'desc']
         */
        if (isset($params['sorting'])) {
            $this->setSorting(key($params['sorting']), current($params['sorting']));
        }
        /**
         * Можно в формате: ['name' => 'label'] - либо в стандартном формате \CAdminList::AddHeaders
         */
        if (isset($params['headers'])) {
            $this->setHeaders($params['headers']);
        }

        if (isset($params['resultCallback'])) {
            $this->setResultCallback($params['resultCallback']);
        }
        if (isset($params['filter'])) {
            $this->setFilterFields($params['filter']);
        }
        if (isset($params['primaryField'])) {
            $this->setPrimaryField($params['primaryField']);
        }
        if (isset($params['rowRendererCallback'])) {
            $this->setRowRenderer($params['rowRendererCallback']);
        }
    }

    public function setFilterFields(array $fields)
    {
        $labels = array_column($this->headers, 'content', 'id');
        $this->filterFields = [];
        foreach ($fields as $key => $value) {
            $key = $value['id'];
            $value['name'] = ($value['name'] ?? $labels[$key]) ?: $key;
            $value['type'] = $value['type'] ?? 'text';
            $this->filterFields[$key] = $value;
        }
    }

    public function getActiveFilter()
    {
        $this->activeFilter = [];

        $fieldNames = array_keys($this->filterFields);
        foreach ($fieldNames as $name) {
            if (isset($GLOBALS[$name]) && !empty($GLOBALS[$name])) {
                $this->activeFilter[$name] = $GLOBALS[$name];
            }
        }

        return $this->activeFilter;

        // UI List
        // $this->activeFilter = [];
        // $this->getList()->addFilter($this->filterFields, $this->activeFilter);
        // return $this->activeFilter;
    }

    public function getActiveOrder()
    {
        return $this->activeOrder;
    }

    public function setPrimaryField(string $value)
    {
        $this->primaryField = $value;
    }

    public function setSorting(string $column, string $columnOrder = 'asc')
    {
        global $by, $order;

        $this->sorting = new \CAdminSorting($this->id, $column, $columnOrder);
        if (isset($by) && isset($order)) {
            $this->activeOrder = [
                $by => $order,
            ];
        }
        else {
            $this->activeOrder = [
                $column => $columnOrder
            ];
        }
    }

    public function setNavResultCallback(callable $callback)
    {
        $this->navResultCallback = $callback;
    }

    public function callNavResultCallback()
    {
        $limit = $this->result->SIZEN;
        $offset = ($this->result->NavPageNomer - 1) * $limit;
        if ($offset < 0) {
            $offset = 0;
        }

        $order = $this->getActiveOrder();
        $filter = $this->getActiveFilter();
        return call_user_func($this->navResultCallback, $filter, $order, $limit, $offset);
    }

    public function setResultCallback(callable $callback)
    {
        $result = call_user_func($callback, $this->getActiveFilter(), $this->getActiveOrder());
        $this->result = new \CAdminResult($result, $this->id);
    }

    public function setRowRenderer(callable $value)
    {
        $this->rowRendererCallback = $value;
    }

    public function setHeaders(array $headers)
    {
        $this->headers = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $this->headers[] = $value;
            }
            else {
                $this->headers[] = [
                    'id' => $key,
                    'sort' => $key,
                    'content' => $value,
                    'default' => true,
                ];
            }
        }
    }

    public function initList()
    {
        $list = $this->getList();
        if ($this->headers) {
            $list->AddHeaders($this->headers);
        }

        if ($this->filterFields) {
            $list->InitFilter(array_keys($this->filterFields));
        }

        $this->result->NavStart();
        $list->NavText($this->result->getNavPrint("Показано"));

        $fieldNames = array_column($this->headers, 'id');
        if ($this->navResultCallback) {
            $itemsResult = $this->callNavResultCallback();
            while ($row = $itemsResult->fetch()) {
                $listRow = $list->addRow($row[$this->primaryField], $row);
                foreach ($fieldNames as $name) {
                    if ($this->rowRendererCallback) {
                        call_user_func($this->rowRendererCallback, $name, $listRow, $this);
                    }
                    else {
                        $listRow->AddInputField($name);
                    }
                }
            }
        }
        else {
            while ($row = $this->result->NavNext()) {
                $listRow = $list->addRow($row[$this->primaryField], $row);
                foreach ($fieldNames as $name) {
                    if ($this->rowRendererCallback) {
                        call_user_func($this->rowRendererCallback, $name, $listRow, $this);
                    }
                    else {
                        $listRow->AddInputField($name);
                    }
                }
            }
        }

        $list->CheckListMode();
    }

    public function displayFilter()
    {
        global $APPLICATION;

        $labels = [];
        foreach ($this->filterFields as $field) {
            $labels[] = $field['name'] ?? $field['id'];
        }
        $filter = new \CAdminFilter($this->id."_filter", $labels);

        echo "<form name='find_form' method='get' action='{$APPLICATION->GetCurPage()}'>";
        $filter->Begin();

        foreach ($this->filterFields as $field) {
            $name = $field['id'];
            $type = $field['type'];
            $label = $field['name'] ?? $field['id'];
            $value = $this->activeFilter[$name] ?? null;
            ?>
            <tr>
                <td>
                    <b><?= $label ?>:</b>
                </td>
                <td nowrap>
                    <?php if ($type === 'list') { ?>
                        <?php
                        $items = $field['items'] ?? [];
                        $data = [
                            'reference' => array_values($items),
                            'reference_id' => array_keys($items),
                        ];
                        array_unshift($data['reference'], '');
                        array_unshift($data['reference_id'], '');

                        echo SelectBoxFromArray($name, $data, $value, '', '');
                        ?>
                    <?php } else if ($type === 'date') { ?>
                        <?php
                        echo CalendarDate($name, $value, 'find_form');
                        ?>
                    <?php } else { ?>
                        <input type="text" size="25" name="<?= $name ?>" value="<?= htmlspecialchars($value)?>" title="<?= $label ?>">
                    <?php } ?>
                </td>
            </tr>
            <?php
        }

        $filter->Buttons([
            "table_id" => $this->id,
            "url" => $APPLICATION->GetCurPage(),
            "form" => "find_form"
        ]);
        $filter->End();
        echo "</form>";

        // UI list
        // $this->getList()->DisplayFilter($this->filterFields);
    }

    public function displayList()
    {
        $this->getList()->DisplayList();
    }
}
```

Пример использования:
```php
<?php

require_once $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php";

$moduleId = "rpsv.code";
if ($APPLICATION->GetGroupRight($moduleId) == "D") {
    $APPLICATION->AuthForm("Не достаточно прав");
}
$APPLICATION->SetTitle("Просмотр писем");

$list = new AdminList("AdminListImapMessages");
$list->setParams([
    'sorting' => [
        'UF_TIMESTAMP' => 'DESC',
    ],
    'headers' => [
        'ID' => 'Ид',
        'UF_UID' => 'UID',
        'UF_TIMESTAMP' => 'Дата получения',
        'UF_SENDER' => 'Отправитель',
        'UF_SUBJECT' => 'Тема',
        'UF_PROCESSED' => 'Статус',
        'UF_ATTACHMENTS' => 'Файлы',
    ],
    'filter' => [
        [
            'id' => 'UF_TIMESTAMP',
            'type' => 'date',
            "default" => true
        ],
        [
            'id' => 'UF_SENDER',
            "default" => true
        ],
        [
            'id' => 'UF_SUBJECT',
            "default" => true
        ],
        [
            'id' => 'UF_UID',
            "default" => true
        ],
    ],
]);
$list->setResultCallback(function($filter, $order) {
    return ImapMessagesTable::getList([
        'filter' => $filter,
        'select' => [
            'ID',
        ],
    ]);
});
$list->setNavResultCallback(function($filter, $order, $limit, $offset) {
    return ImapMessagesTable::getList([
        'filter' => $filter,
        'order' => $order,
        'offset' => $offset,
        'limit' => $limit,
    ]);
});
$list->setRowRenderer(function($name, $listRow, $list) use($ports, $statuses) {
    if ($name === 'UF_ATTACHMENTS') {
        $html = '-';
        $value = $listRow->arRes[$name];
        if ($value) {
            $value = @unserialize($value);
            if ($value) {
                $i = 0;
                $tmp = [];
                foreach ($value as $file) {
                    $i++;
                    $path = urlencode($file['path']);
                    $link = "fileman_file_view.php?path={$path}&site=s1&lang=ru";
                    $tmp[] = "{$i}. <a href='{$link}' target='_blank'>{$file['name']}</a>";
                }
                $html = join("<br>", $tmp);
            }
            else {
                $html = "(не сериализуемые данные)";
            }
        }
        $listRow->AddViewField($name, $html);
    }
    else if ($name === 'UF_PROCESSED') {
        $html = "Ожидает обработки";
        $processed = $listRow->arRes['UF_PROCESSED'] != 0;
        $processedError = $listRow->arRes['UF_PROCESS_ERROR'];
        if ($processed && $processedError) {
            $html = "Ошибка при обработке: {$processedError}";
        }
        else if ($processed) {
            $html = "Обработано";
        }
        $listRow->AddViewField($name, $html);
        $listRow->AddActions([
            [
                'ICON' => '',
                'TEXT' => 'Обработка',
                'ACTION' => $listRow->pList->ActionRedirect("rpsv_core_mail_process.php?id={$listRow->arRes['ID']}&".bitrix_sessid_get()),
            ],
        ]);
    }
    else {
        $listRow->AddInputField($name);
    }
});
$list->initList();

require_once $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php";
?>

<?php
$list->displayFilter();
$list->displayList();
?>
```
