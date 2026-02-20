<?php

/** @var yii\web\View $this */
/** @var common\models\SupplierFetchConfig $model */
/** @var common\models\Supplier $supplier */

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

$this->title = "Настройка получения: {$supplier->name}";
$this->params['breadcrumbs'][] = ['label' => 'Поставщики', 'url' => ['/supplier/index']];
$this->params['breadcrumbs'][] = ['label' => $supplier->name, 'url' => ['/supplier/view', 'id' => $supplier->id]];
$this->params['breadcrumbs'][] = 'Настройка получения';
?>
<div class="fetch-config-update">

    <h3 class="mb-4" style="font-weight:700"><?= Html::encode($this->title) ?></h3>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <?php $form = ActiveForm::begin(['id' => 'fetch-config-form']); ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <?= $form->field($model, 'fetch_method')->dropDownList([
                                'manual' => 'Ручная загрузка',
                                'url' => 'По ссылке (HTTP/HTTPS)',
                                'ftp' => 'FTP / SFTP',
                                'email' => 'Email (IMAP)',
                                'api' => 'API поставщика',
                            ], ['id' => 'fetch-method-select']) ?>
                        </div>
                        <div class="col-md-3">
                            <?= $form->field($model, 'is_enabled')->checkbox() ?>
                        </div>
                    </div>

                    <!-- ═══ URL Section ═══ -->
                    <div class="method-section" data-method="url" style="display:none">
                        <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                            <div class="card-header" style="font-size:.9rem;font-weight:600">Настройки URL</div>
                            <div class="card-body">
                                <?= $form->field($model, 'url')->textInput([
                                    'maxlength' => 1000,
                                    'placeholder' => 'https://example.com/price-list.xml',
                                ])->hint('Прямая ссылка на файл прайса') ?>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ FTP Section ═══ -->
                    <div class="method-section" data-method="ftp" style="display:none">
                        <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                            <div class="card-header" style="font-size:.9rem;font-weight:600">Настройки FTP</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <?= $form->field($model, 'ftp_host')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'ftp.example.com',
                                        ]) ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?= $form->field($model, 'ftp_port')->textInput([
                                            'type' => 'number',
                                            'placeholder' => '21',
                                        ])->hint('21 = FTP, 22 = SFTP') ?>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'ftp_user')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'username',
                                        ]) ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'ftp_password')->passwordInput([
                                            'maxlength' => 255,
                                            'placeholder' => '••••••••',
                                        ])->label('FTP пароль') ?>
                                    </div>
                                </div>
                                <?= $form->field($model, 'ftp_path')->textInput([
                                    'maxlength' => 500,
                                    'placeholder' => '/prices/price-list.xml',
                                ])->hint('Путь к файлу или директории на FTP-сервере') ?>
                                <?= $form->field($model, 'ftp_passive')->checkbox()->label('Пассивный режим (PASV)') ?>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ Email Section ═══ -->
                    <div class="method-section" data-method="email" style="display:none">
                        <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                            <div class="card-header" style="font-size:.9rem;font-weight:600">Настройки Email (IMAP)</div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <?= $form->field($model, 'email_host')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'imap.gmail.com',
                                        ]) ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?= $form->field($model, 'email_port')->textInput([
                                            'type' => 'number',
                                            'placeholder' => '993',
                                        ])->label('IMAP порт') ?>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'email_user')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'prices@company.ru',
                                        ]) ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'email_password')->passwordInput([
                                            'maxlength' => 255,
                                        ])->label('Пароль почты') ?>
                                    </div>
                                </div>
                                <?= $form->field($model, 'email_folder')->textInput([
                                    'maxlength' => 100,
                                    'placeholder' => 'INBOX',
                                ])->hint('Папка для поиска писем (INBOX по умолчанию)') ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'email_subject_filter')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'Прайс-лист',
                                        ])->hint('Поиск по теме письма') ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?= $form->field($model, 'email_from_filter')->textInput([
                                            'maxlength' => 255,
                                            'placeholder' => 'price@supplier.com',
                                        ])->hint('Фильтр по отправителю') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ API Section ═══ -->
                    <div class="method-section" data-method="api" style="display:none">
                        <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                            <div class="card-header" style="font-size:.9rem;font-weight:600">Настройки API</div>
                            <div class="card-body">
                                <?= $form->field($model, 'api_url')->textInput([
                                    'maxlength' => 1000,
                                    'placeholder' => 'https://api.supplier.com/v1/products',
                                ]) ?>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <?= $form->field($model, 'api_key')->textInput([
                                            'maxlength' => 500,
                                            'placeholder' => 'Bearer token или API-ключ',
                                        ]) ?>
                                    </div>
                                    <div class="col-md-4">
                                        <?= $form->field($model, 'api_method')->dropDownList([
                                            'GET' => 'GET',
                                            'POST' => 'POST',
                                        ], ['prompt' => 'GET']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ File Settings ═══ -->
                    <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                        <div class="card-header" style="font-size:.9rem;font-weight:600">Параметры файла</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <?= $form->field($model, 'file_format')->dropDownList([
                                        'xml' => 'XML',
                                        'csv' => 'CSV',
                                        'xlsx' => 'Excel (XLSX)',
                                        'json' => 'JSON',
                                        'yml' => 'YML',
                                    ], ['prompt' => '— Авто —']) ?>
                                </div>
                                <div class="col-md-4">
                                    <?= $form->field($model, 'file_encoding')->dropDownList([
                                        'UTF-8' => 'UTF-8',
                                        'Windows-1251' => 'Windows-1251',
                                        'CP866' => 'CP866',
                                        'ISO-8859-1' => 'ISO-8859-1',
                                    ], ['prompt' => '— Авто —']) ?>
                                </div>
                                <div class="col-md-4">
                                    <?= $form->field($model, 'archive_type')->dropDownList([
                                        'zip' => 'ZIP',
                                        'gz' => 'GZIP',
                                        'rar' => 'RAR',
                                        '7z' => '7-Zip',
                                    ], ['prompt' => '— Без архива —']) ?>
                                </div>
                            </div>
                            <?= $form->field($model, 'file_pattern')->textInput([
                                'maxlength' => 255,
                                'placeholder' => '*.xml или price_*.csv',
                            ])->hint('Паттерн для выбора файла (при нескольких файлах в архиве или на FTP)') ?>
                        </div>
                    </div>

                    <!-- ═══ Schedule ═══ -->
                    <div class="card mb-3" style="background:var(--bg-input);border-color:var(--border)">
                        <div class="card-header" style="font-size:.9rem;font-weight:600">Расписание</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <?= $form->field($model, 'schedule_cron')->textInput([
                                        'maxlength' => 100,
                                        'placeholder' => '0 6 * * *',
                                    ])->hint('Cron-формат: минута час день месяц день_недели') ?>
                                </div>
                                <div class="col-md-6">
                                    <?= $form->field($model, 'schedule_interval_hours')->textInput([
                                        'type' => 'number',
                                        'placeholder' => '24',
                                    ])->hint('Альтернатива cron: раз в N часов') ?>
                                </div>
                            </div>
                            <div style="font-size:.8rem;color:var(--text-secondary);margin-top:8px">
                                Примеры cron: <code>0 6 * * *</code> — каждый день в 6:00,
                                <code>0 */4 * * *</code> — каждые 4 часа,
                                <code>0 8 * * 1</code> — каждый понедельник в 8:00
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <div>
                            <?= Html::submitButton('Сохранить', ['class' => 'btn btn-accent']) ?>
                            <?= Html::a('Отмена', ['/supplier/view', 'id' => $supplier->id], ['class' => 'btn btn-dark-outline ms-2']) ?>
                        </div>
                        <?php if (!$model->isNewRecord && $model->fetch_method !== 'manual'): ?>
                            <form method="post" action="<?= \yii\helpers\Url::to(['/supplier-fetch-config/test-fetch', 'supplierId' => $supplier->id]) ?>">
                                <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                                <button type="submit" class="btn btn-dark-outline" onclick="return confirm('Запустить тестовое получение прайса?')">
                                    &#9654; Тестовый запуск
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>

        <!-- ═══ Status Sidebar ═══ -->
        <div class="col-xl-4">
            <?php if (!$model->isNewRecord): ?>
                <div class="card mb-3">
                    <div class="card-header">Статус</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0" style="font-size:.85rem">
                            <tr>
                                <td style="color:var(--text-secondary);border:none;width:45%">Метод</td>
                                <td style="border:none">
                                    <span class="badge-status badge-<?= $model->fetch_method === 'manual' ? 'draft' : 'active' ?>">
                                        <?= Html::encode($model->getMethodLabel()) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Статус</td>
                                <td style="border:none">
                                    <span class="badge-status badge-<?= $model->is_enabled ? 'active' : 'inactive' ?>">
                                        <?= $model->is_enabled ? 'Включён' : 'Отключён' ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Загрузок</td>
                                <td style="border:none"><?= number_format($model->fetch_count) ?></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Последняя</td>
                                <td style="border:none">
                                    <?= $model->last_fetch_at
                                        ? Yii::$app->formatter->asDatetime($model->last_fetch_at) . '<br><small style="color:var(--text-secondary)">' . Yii::$app->formatter->asRelativeTime($model->last_fetch_at) . '</small>'
                                        : '<span style="color:var(--text-secondary)">никогда</span>'
                                    ?>
                                </td>
                            </tr>
                            <?php if ($model->last_fetch_status): ?>
                                <tr>
                                    <td style="color:var(--text-secondary);border:none">Рез-тат</td>
                                    <td style="border:none">
                                        <span class="badge-status badge-<?= $model->last_fetch_status === 'success' ? 'active' : ($model->last_fetch_status === 'error' ? 'inactive' : 'partial') ?>">
                                            <?= Html::encode($model->last_fetch_status) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($model->last_fetch_error): ?>
                                <tr>
                                    <td style="color:var(--text-secondary);border:none">Ошибка</td>
                                    <td style="border:none;color:#f87171;font-size:.8rem"><?= Html::encode($model->last_fetch_error) ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">Справка</div>
                <div class="card-body" style="font-size:.83rem;color:var(--text-secondary)">
                    <p><strong>Ручная загрузка</strong> — файл загружается через админку на странице поставщика.</p>
                    <p><strong>По ссылке</strong> — система скачивает файл по HTTP/HTTPS URL по расписанию.</p>
                    <p><strong>FTP</strong> — подключение к FTP-серверу поставщика для скачивания файла.</p>
                    <p><strong>Email (IMAP)</strong> — мониторинг почтового ящика на наличие писем с прайсом во вложении.</p>
                    <p><strong>API</strong> — получение данных через REST API поставщика.</p>
                    <hr style="border-color:var(--border)">
                    <p class="mb-0"><strong>Расписание</strong> — задаётся в формате cron или через интервал в часах. Планировщик проверяет расписание каждую минуту.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$this->registerJs(<<<JS
    function toggleMethodSections() {
        var method = document.getElementById('fetch-method-select').value;
        document.querySelectorAll('.method-section').forEach(function(el) {
            el.style.display = el.dataset.method === method ? 'block' : 'none';
        });
    }
    document.getElementById('fetch-method-select').addEventListener('change', toggleMethodSections);
    toggleMethodSections();
JS);
?>
