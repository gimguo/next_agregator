<?php

namespace backend\modules\api;

use yii\base\Module;

/**
 * REST API модуль агрегатора.
 *
 * Эндпоинты:
 *   GET  /api/v1/cards          — список карточек (пагинация, фильтры)
 *   GET  /api/v1/cards/{id}     — одна карточка с офферами и картинками
 *   GET  /api/v1/brands         — список брендов
 *   GET  /api/v1/categories     — дерево категорий
 *   GET  /api/v1/suppliers      — список поставщиков
 *   GET  /api/v1/stats          — общая статистика
 *   GET  /api/v1/updated        — карточки, изменённые с указанной даты
 */
class ApiModule extends Module
{
    public $controllerNamespace = 'backend\modules\api\controllers';
}
