<?php

namespace common\services\marketplace;

use yii\base\Component;
use Yii;

/**
 * Мок-реализация MarketplaceApiClientInterface.
 *
 * Вместо реальной отправки на витрину — пишет payload в лог Yii.
 * Используется на этапе разработки, пока API RosMatras не готов к приёму.
 *
 * В будущем заменяется на HttpMarketplaceClient с реальным HTTP-клиентом.
 *
 * Логи пишутся в категорию 'marketplace.export' — легко фильтровать.
 */
class LogMarketplaceClient extends Component implements MarketplaceApiClientInterface
{
    /** @var bool Симулировать случайные ошибки (для тестирования retry) */
    public bool $simulateErrors = false;

    /** @var float Вероятность ошибки (0.0-1.0) */
    public float $errorRate = 0.05;

    /** @var array Статистика текущей сессии */
    private array $stats = [
        'pushed'  => 0,
        'errors'  => 0,
        'deleted' => 0,
    ];

    /**
     * {@inheritdoc}
     */
    public function pushProduct(int $modelId, array $projection): bool
    {
        // Симуляция ошибки
        if ($this->simulateErrors && mt_rand(1, 100) <= ($this->errorRate * 100)) {
            $this->stats['errors']++;
            Yii::warning(
                "[MOCK] Export FAILED for model_id={$modelId} (simulated error)",
                'marketplace.export'
            );
            throw new \RuntimeException("Simulated API error for model {$modelId}");
        }

        // Компактный лог: основные поля
        $summary = [
            'model_id'       => $modelId,
            'name'           => $projection['name'] ?? '?',
            'brand'          => $projection['brand']['name'] ?? null,
            'family'         => $projection['product_family'] ?? null,
            'best_price'     => $projection['best_price'] ?? null,
            'variant_count'  => count($projection['variants'] ?? []),
            'offer_count'    => $projection['offer_count'] ?? 0,
            'is_in_stock'    => $projection['is_in_stock'] ?? false,
            'image_count'    => count($projection['images'] ?? []),
            'axes'           => array_keys(array_diff_key($projection['selector_axes'] ?? [], ['axis_combinations' => 1])),
        ];

        Yii::info(
            "[MOCK] Exported model_id={$modelId}: " . json_encode($summary, JSON_UNESCAPED_UNICODE),
            'marketplace.export'
        );

        // Полный payload — для отладки (debug level)
        Yii::debug(
            "[MOCK] Full projection model_id={$modelId}: " . json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'marketplace.export'
        );

        $this->stats['pushed']++;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function pushBatch(array $projections): array
    {
        $results = [];
        foreach ($projections as $modelId => $projection) {
            try {
                $results[$modelId] = $this->pushProduct($modelId, $projection);
            } catch (\Throwable $e) {
                $results[$modelId] = false;
                Yii::error(
                    "[MOCK] Batch export error for model_id={$modelId}: " . $e->getMessage(),
                    'marketplace.export'
                );
            }
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteProduct(int $modelId): bool
    {
        Yii::info(
            "[MOCK] Deleted model_id={$modelId} from marketplace",
            'marketplace.export'
        );
        $this->stats['deleted']++;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function healthCheck(): bool
    {
        Yii::info("[MOCK] Health check: OK", 'marketplace.export');
        return true;
    }

    /**
     * Статистика текущей сессии.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Сброс статистики.
     */
    public function resetStats(): void
    {
        $this->stats = ['pushed' => 0, 'errors' => 0, 'deleted' => 0];
    }
}
