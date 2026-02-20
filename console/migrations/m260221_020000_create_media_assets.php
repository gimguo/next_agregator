<?php

use yii\db\Migration;

/**
 * Digital Asset Management — таблица media_assets.
 *
 * Централизованное хранилище изображений для MDM-каталога.
 *
 * Ключевые решения:
 *   - file_hash (MD5 содержимого) — дедупликация: если 50 поставщиков
 *     прислали одно и то же фото матраса по разным URL, мы скачаем его один раз.
 *   - source_url_hash (MD5 URL) — уникальный индекс для быстрой проверки
 *     "уже ли мы обрабатывали эту ссылку?"
 *   - local_path — относительный путь в нашем хранилище (uploads/media/1a/2b/hash.webp)
 *   - Иерархия директорий по хешу: ab/cd/abcdef...webp — чтобы не было 100K файлов в одной папке
 *
 * Связи:
 *   - entity_type + entity_id → polymorphic link к model/variant/offer
 *   - sort_order — для упорядочивания изображений в галерее
 *   - is_primary — главное фото модели/варианта
 *
 * Жизненный цикл:
 *   pending → downloading → processed (WebP готов) | error
 *   При дедупликации по file_hash: pending → deduplicated (ссылка на существующий файл)
 */
class m260221_020000_create_media_assets extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%media_assets}}', [
            'id'              => $this->bigPrimaryKey(),

            // ═══ Привязка (полиморфная) ═══
            'entity_type'     => $this->string(20)->notNull(),       // 'model', 'variant', 'offer'
            'entity_id'       => $this->integer()->notNull(),

            // ═══ Источник ═══
            'source_url'      => $this->text()->notNull(),           // Исходная ссылка от поставщика
            'source_url_hash' => $this->string(32)->notNull(),       // MD5(source_url) — для быстрой проверки дублей URL

            // ═══ Дедупликация по содержимому ═══
            'file_hash'       => $this->string(32),                  // MD5 содержимого файла (после скачивания)

            // ═══ Локальное хранилище ═══
            'local_path'      => $this->string(500),                 // uploads/media/1a/2b/1a2b3c4d...webp
            'webp_path'       => $this->string(500),                 // WebP-версия (основная для витрины)
            'thumb_path'      => $this->string(500),                 // Миниатюра 300x300

            // ═══ Метаданные ═══
            'mime_type'       => $this->string(50),                  // image/webp, image/jpeg...
            'size_bytes'      => $this->integer(),                   // Размер файла в байтах
            'width'           => $this->smallInteger(),
            'height'          => $this->smallInteger(),

            // ═══ Управление ═══
            'status'          => $this->string(20)->notNull()->defaultValue('pending'),
                                                                      // 'pending', 'downloading', 'processed', 'deduplicated', 'error'
            'is_primary'      => $this->boolean()->notNull()->defaultValue(false),
            'sort_order'      => $this->smallInteger()->notNull()->defaultValue(0),
            'error_message'   => $this->text(),
            'attempts'        => $this->smallInteger()->notNull()->defaultValue(0),

            // ═══ Связь с дедуплицированным файлом ═══
            'original_asset_id' => $this->bigInteger(),              // Если дедуплицирован — ссылка на оригинал

            // ═══ Время ═══
            'created_at'      => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'      => $this->timestamp()->defaultExpression('NOW()'),
            'processed_at'    => $this->timestamp(),
        ]);

        // ═══ ИНДЕКСЫ ═══

        // Главный рабочий индекс: поиск по entity
        $this->createIndex(
            'idx-media_assets-entity',
            '{{%media_assets}}',
            ['entity_type', 'entity_id']
        );

        // Дедупликация по содержимому файла — ключевой индекс
        $this->createIndex(
            'idx-media_assets-file_hash',
            '{{%media_assets}}',
            'file_hash'
        );

        // Дедупликация по URL — чтобы не создавать дубли media_assets для одного URL
        $this->createIndex(
            'idx-media_assets-source_url_hash',
            '{{%media_assets}}',
            ['entity_type', 'entity_id', 'source_url_hash'],
            true  // UNIQUE — один URL на одну сущность
        );

        // Worker забирает pending для скачивания
        $this->createIndex(
            'idx-media_assets-status',
            '{{%media_assets}}',
            ['status', 'created_at']
        );

        // Быстрый доступ к primary-фото
        $this->createIndex(
            'idx-media_assets-primary',
            '{{%media_assets}}',
            ['entity_type', 'entity_id', 'is_primary']
        );

        // Ссылка на оригинал (для дедуплицированных)
        $this->createIndex(
            'idx-media_assets-original',
            '{{%media_assets}}',
            'original_asset_id'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%media_assets}}');
    }
}
