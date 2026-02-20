<?php

use yii\db\Migration;

/**
 * Перестройка media_assets: замена локального хранилища на S3/MinIO.
 *
 * Изменения:
 *   - Удаляем: local_path, webp_path, thumb_path
 *   - Добавляем: s3_bucket, s3_key, s3_thumb_key
 *   - Обновляем уникальный индекс
 *
 * Все существующие записи со статусами 'processed'/'deduplicated' сбрасываются
 * в 'pending', так как их нужно перекачать в S3.
 */
class m260221_030000_rebuild_media_assets_for_s3 extends Migration
{
    public function safeUp()
    {
        // 1. Добавляем S3-поля
        $this->addColumn('{{%media_assets}}', 's3_bucket', $this->string(100)->after('file_hash'));
        $this->addColumn('{{%media_assets}}', 's3_key', $this->string(500)->after('s3_bucket'));
        $this->addColumn('{{%media_assets}}', 's3_thumb_key', $this->string(500)->after('s3_key'));

        // 2. Сбрасываем все "обработанные" записи — их нужно перекачать в S3
        $this->update('{{%media_assets}}', [
            'status' => 'pending',
            'attempts' => 0,
            'error_message' => null,
        ], ['status' => ['processed', 'deduplicated', 'downloading']]);

        // 3. Удаляем старые локальные поля
        $this->dropColumn('{{%media_assets}}', 'local_path');
        $this->dropColumn('{{%media_assets}}', 'webp_path');
        $this->dropColumn('{{%media_assets}}', 'thumb_path');

        // 4. Добавляем индекс по s3_key для быстрого поиска
        $this->createIndex(
            'idx-media_assets-s3_key',
            '{{%media_assets}}',
            's3_key'
        );
    }

    public function safeDown()
    {
        // Удаляем S3 индекс
        $this->dropIndex('idx-media_assets-s3_key', '{{%media_assets}}');

        // Возвращаем локальные поля
        $this->addColumn('{{%media_assets}}', 'local_path', $this->string(500));
        $this->addColumn('{{%media_assets}}', 'webp_path', $this->string(500));
        $this->addColumn('{{%media_assets}}', 'thumb_path', $this->string(500));

        // Удаляем S3 поля
        $this->dropColumn('{{%media_assets}}', 's3_thumb_key');
        $this->dropColumn('{{%media_assets}}', 's3_key');
        $this->dropColumn('{{%media_assets}}', 's3_bucket');
    }
}
