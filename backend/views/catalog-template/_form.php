<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogTemplate $model
 * @var bool $isSystem
 */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\widgets\ActiveForm;

$isSystem = $isSystem ?? false;
?>

<?php $form = ActiveForm::begin(['id' => 'catalog-template-form']); ?>

<?= $form->field($model, 'name')
    ->textInput([
        'maxlength' => true,
        'disabled' => $isSystem,
    ])
    ->label('Название шаблона')
    ->hint($isSystem ? 'Системные шаблоны нельзя переименовывать' : 'Уникальное название для идентификации шаблона') ?>

<?= $form->field($model, 'description')
    ->textarea(['rows' => 3])
    ->label('Описание')
    ->hint('Краткое описание назначения шаблона (необязательно)') ?>

<?php
// Подготовка JSON для редактора
$structureJson = $model->structure_json;
if (is_array($structureJson)) {
    $structureJsonForJs = Json::htmlEncode($structureJson);
} elseif (is_string($structureJson) && !empty($structureJson)) {
    // Если это уже строка, пробуем распарсить для валидации
    $decoded = json_decode($structureJson, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $structureJsonForJs = Json::htmlEncode($decoded);
    } else {
        $structureJsonForJs = Json::htmlEncode(['categories' => []]);
    }
} else {
    $structureJsonForJs = Json::htmlEncode(['categories' => []]);
}
?>

<div class="form-group field-catalogtemplate-structure_json">
    <label class="control-label" for="catalogtemplate-structure_json">
        Структура категорий (JSON)
    </label>
    <div id="jsoneditor-structure" style="height: 500px; border: 1px solid var(--border); border-radius: 6px;"></div>
    <div class="help-block">
        JSON структура категорий каталога с правилами распределения. 
        Пример: {"categories": [{"id": 1, "name": "Матрасы", "slug": "matrasy", "rules": {"family": ["mattress"]}, "children": []}]}
    </div>
    <?= Html::hiddenInput('CatalogTemplate[structure_json]', '', ['id' => 'catalogtemplate-structure_json']) ?>
    <div class="help-block"></div>
</div>

<?= $form->field($model, 'merge_rules')
    ->textarea(['rows' => 5, 'style' => 'font-family:monospace;font-size:.85rem'])
    ->label('Правила объединения (JSON)')
    ->hint('JSON правила объединения категорий (необязательно)') ?>

<div class="form-group mt-4">
    <?= Html::submitButton(
        '<i class="fas fa-save me-1"></i> ' . ($model->isNewRecord ? 'Создать' : 'Сохранить'),
        ['class' => 'btn btn-accent btn-lg']
    ) ?>
</div>

<?php ActiveForm::end(); ?>

<?php
// Подключение JSON Editor через CDN
$this->registerCssFile('https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.css', ['depends' => [\yii\web\JqueryAsset::class]]);
$this->registerJsFile('https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.js', ['depends' => [\yii\web\JqueryAsset::class]]);

$jsonEditorInit = <<<JS
(function() {
    var container = document.getElementById('jsoneditor-structure');
    if (!container) return;
    
    var options = {
        mode: 'tree',
        modes: ['code', 'tree', 'view'],
        search: true,
        history: true,
        navigationBar: true,
        statusBar: true,
        onError: function(err) {
            console.error('JSON Editor error:', err);
        },
        onModeChange: function(newMode) {
            console.log('Mode changed to:', newMode);
        }
    };
    
    var editor = new JSONEditor(container, options);
    
    // Загружаем начальные данные
    var initialData = {$structureJsonForJs};
    try {
        editor.set(initialData);
    } catch (e) {
        console.error('Error setting initial data:', e);
        editor.set({categories: []});
    }
    
    // Перед сабмитом формы забираем данные из редактора и кладём в скрытый input
    var form = document.getElementById('catalog-template-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            try {
                var jsonData = editor.get();
                var jsonString = JSON.stringify(jsonData, null, 2);
                document.getElementById('catalogtemplate-structure_json').value = jsonString;
            } catch (err) {
                e.preventDefault();
                alert('Ошибка в JSON структуре: ' + err.message);
                return false;
            }
        });
    }
    
    // Сохраняем редактор в глобальной переменной для отладки
    window.jsonEditor = editor;
})();
JS;

$this->registerJs($jsonEditorInit, \yii\web\View::POS_READY);
?>
