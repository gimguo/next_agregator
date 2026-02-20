<?php

namespace common\services\matching;

use common\dto\MatchResult;
use common\dto\ProductDTO;
use yii\base\Component;
use Yii;

/**
 * Сервис сопоставления товаров — Chain of Responsibility.
 *
 * Прогоняет нормализованный ProductDTO по цепочке матчеров:
 *   1. GtinMatcher    (GTIN/EAN штрихкод → 100% совпадение)
 *   2. MpnMatcher     (Brand + MPN артикул → 95% совпадение)
 *   3. CompositeAttributeMatcher (Family + Brand + Model + Attrs → 70-85%)
 *
 * Если ни один не дал результат → товар новый.
 *
 * Каждый матч логируется в matching_log для отладки ложных склеек.
 *
 * Использование:
 *   $service = Yii::$app->get('matchingService');
 *   $result = $service->match($dto, ['supplier_id' => 1, 'session_id' => 'abc']);
 *   if ($result->isMatched()) { ... }
 */
class MatchingService extends Component
{
    /** @var ProductMatcherInterface[] Цепочка матчеров */
    private array $matchers = [];

    /** @var bool Логировать результаты в matching_log */
    public bool $logResults = true;

    /** @var array Статистика текущей сессии */
    private array $stats = [
        'total'     => 0,
        'matched'   => 0,
        'new'       => 0,
        'by_matcher' => [],
    ];

    public function init(): void
    {
        parent::init();

        // Регистрируем матчеры в порядке приоритета
        $this->registerMatcher(new GtinMatcher());
        $this->registerMatcher(new MpnMatcher());
        $this->registerMatcher(new CompositeAttributeMatcher());
    }

    /**
     * Зарегистрировать матчер в цепочке.
     */
    public function registerMatcher(ProductMatcherInterface $matcher): void
    {
        $this->matchers[] = $matcher;

        // Сортируем по приоритету (меньше = раньше)
        usort($this->matchers, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    /**
     * Прогнать DTO по цепочке матчеров.
     *
     * @param ProductDTO $dto  Нормализованный товар
     * @param array      $context  Доп. контекст:
     *   - supplier_id (int) — ID поставщика
     *   - brand_id (int) — resolved brand ID
     *   - product_family (string) — ProductFamily value
     *   - session_id (string) — ID import session (для логирования)
     *
     * @return MatchResult  Результат (всегда возвращается, даже если не найден)
     */
    public function match(ProductDTO $dto, array $context = []): MatchResult
    {
        $this->stats['total']++;

        foreach ($this->matchers as $matcher) {
            try {
                $result = $matcher->match($dto, $context);

                if ($result !== null) {
                    // Матчер дал результат!
                    $this->stats['matched']++;
                    $matcherName = $matcher->getName();
                    $this->stats['by_matcher'][$matcherName] = ($this->stats['by_matcher'][$matcherName] ?? 0) + 1;

                    if ($this->logResults) {
                        $this->logMatch($dto, $result, $context);
                    }

                    return $result;
                }
            } catch (\Throwable $e) {
                Yii::warning(
                    "MatchingService: ошибка в {$matcher->getName()}: {$e->getMessage()}",
                    'matching'
                );
                // Продолжаем со следующим матчером
            }
        }

        // Ни один матчер не дал результат → товар новый
        $this->stats['new']++;
        $this->stats['by_matcher']['new'] = ($this->stats['by_matcher']['new'] ?? 0) + 1;

        $result = MatchResult::notFound([
            'sku'          => $dto->supplierSku,
            'name'         => $dto->name,
            'manufacturer' => $dto->manufacturer,
            'model'        => $dto->model,
            'reason'       => 'No matcher found a match',
        ]);

        if ($this->logResults) {
            $this->logMatch($dto, $result, $context);
        }

        return $result;
    }

    /**
     * Записать лог матчинга в БД.
     */
    protected function logMatch(ProductDTO $dto, MatchResult $result, array $context): void
    {
        try {
            Yii::$app->db->createCommand()->insert('{{%matching_log}}', [
                'import_session_id'  => $context['session_id'] ?? null,
                'supplier_id'        => $context['supplier_id'] ?? 0,
                'supplier_sku'       => $dto->supplierSku,
                'matched_model_id'   => $result->modelId,
                'matched_variant_id' => $result->variantId,
                'matcher_name'       => $result->matcherName,
                'confidence'         => $result->confidence,
                'match_details'      => json_encode($result->details, JSON_UNESCAPED_UNICODE),
            ])->execute();
        } catch (\Throwable $e) {
            // Логирование не должно ломать основной процесс
            Yii::warning("MatchingService: ошибка записи лога: {$e->getMessage()}", 'matching');
        }
    }

    /**
     * Получить статистику текущей сессии.
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Сбросить статистику.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total'     => 0,
            'matched'   => 0,
            'new'       => 0,
            'by_matcher' => [],
        ];
    }

    /**
     * Получить список зарегистрированных матчеров.
     */
    public function getMatcherNames(): array
    {
        return array_map(fn($m) => $m->getName() . ' (priority=' . $m->getPriority() . ')', $this->matchers);
    }
}
