<?php

use yii\db\Migration;

/**
 * Делает card_id nullable в supplier_offers.
 *
 * Причина: CatalogPersisterService (MDM-движок) записывает офферы через
 * model_id + variant_id (новая 3-уровневая иерархия). card_id — legacy поле
 * для обратной совместимости с admin panel и API v1.
 *
 * Также обновляем триггер trigger_refresh_card(), чтобы он пропускал
 * записи с card_id = NULL (MDM-офферы).
 */
class m260220_210000_make_card_id_nullable_in_offers extends Migration
{
    public function safeUp()
    {
        // 1. Делаем card_id nullable
        $this->execute("ALTER TABLE {{%supplier_offers}} ALTER COLUMN card_id DROP NOT NULL");
        $this->execute("ALTER TABLE {{%supplier_offers}} ALTER COLUMN card_id SET DEFAULT NULL");

        // 2. Обновляем триггер — пропускать refresh если card_id IS NULL
        $this->execute("
            CREATE OR REPLACE FUNCTION trigger_refresh_card() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.card_id IS NOT NULL THEN
                        PERFORM refresh_card_aggregates(OLD.card_id);
                    END IF;
                    RETURN OLD;
                ELSE
                    IF NEW.card_id IS NOT NULL THEN
                        PERFORM refresh_card_aggregates(NEW.card_id);
                    END IF;
                    RETURN NEW;
                END IF;
            END;
            \$\$ LANGUAGE plpgsql
        ");
    }

    public function safeDown()
    {
        // Возвращаем NOT NULL (сначала заполняем NULL значения placeholder-ом)
        // Внимание: это может быть деструктивно если есть офферы без card_id
        $this->execute("UPDATE {{%supplier_offers}} SET card_id = 0 WHERE card_id IS NULL");
        $this->execute("ALTER TABLE {{%supplier_offers}} ALTER COLUMN card_id SET NOT NULL");

        // Возвращаем оригинальный триггер
        $this->execute("
            CREATE OR REPLACE FUNCTION trigger_refresh_card() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM refresh_card_aggregates(OLD.card_id);
                    RETURN OLD;
                ELSE
                    PERFORM refresh_card_aggregates(NEW.card_id);
                    RETURN NEW;
                END IF;
            END;
            \$\$ LANGUAGE plpgsql
        ");
    }
}
