<?php

namespace common\components\parsers;

use common\dto\ProductDTO;
use common\dto\VariantDTO;
use Yii;

/**
 * Парсер XML прайс-листа Ormatek.
 *
 * Формат: XML с элементами <price-item>.
 * Каждый <price-item> — один вариант (размер/декор) конкретной модели.
 * Группировка по model-name для формирования ProductDTO.
 *
 * Размер файла: до 1.5GB → XMLReader (потоковое чтение).
 */
class OrmatekXmlParser extends BaseParser
{
    public function getSupplierCode(): string
    {
        return 'ormatek';
    }

    public function getSupplierName(): string
    {
        return 'Орматек';
    }

    public function accepts(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'xml') {
            return false;
        }

        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            return false;
        }

        $found = false;
        $depth = 0;
        while ($reader->read() && $depth < 50) {
            $depth++;
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if (in_array($reader->name, ['price-items', 'price-item'])) {
                    $found = true;
                    break;
                }
            }
        }
        $reader->close();

        return $found;
    }

    /**
     * @return \Generator<int, ProductDTO>
     */
    public function parse(string $filePath, array $options = []): \Generator
    {
        $maxProducts = (int)($options['max_products'] ?? 0);
        $skipImages = (bool)($options['skip_images'] ?? false);
        $maxImagesPerProduct = (int)($options['max_images_per_product'] ?? 5);

        Yii::info("Ormatek: начало парсинга {$filePath}", 'import');

        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            Yii::error("Ormatek: не удалось открыть XML: {$filePath}", 'import');
            return;
        }

        $productCount = 0;
        $itemCount = 0;
        $currentGroupKey = null;
        $currentItems = [];

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'price-item') {
                continue;
            }

            $xmlString = $reader->readOuterXml();
            if (empty($xmlString)) {
                $this->stats['errors']++;
                continue;
            }

            $item = $this->parsePriceItem($xmlString);
            unset($xmlString);

            if ($item === null) {
                $this->stats['errors']++;
                continue;
            }

            $itemCount++;
            $this->stats['total_parsed']++;

            if ($item['price'] <= 0) {
                $this->stats['skipped']++;
                continue;
            }

            $groupKey = ($item['brand_name'] ?? '') . '::' . ($item['model_name'] ?? '');

            if ($currentGroupKey !== null && $currentGroupKey !== $groupKey && !empty($currentItems)) {
                $dto = $this->buildProductDTO($currentItems, $skipImages, $maxImagesPerProduct);
                if ($dto !== null) {
                    $productCount++;
                    yield $dto;

                    if ($maxProducts > 0 && $productCount >= $maxProducts) {
                        Yii::info("Ormatek: лимит {$maxProducts} товаров", 'import');
                        break;
                    }
                }
                $currentItems = [];
            }

            $currentGroupKey = $groupKey;
            $currentItems[] = $item;

            if ($itemCount % 5000 === 0) {
                $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                Yii::info("Ormatek: прогресс items={$itemCount} products={$productCount} mem={$mem}MB", 'import');
            }
        }

        // Последняя группа
        if (!empty($currentItems) && ($maxProducts === 0 || $productCount < $maxProducts)) {
            $dto = $this->buildProductDTO($currentItems, $skipImages, $maxImagesPerProduct);
            if ($dto !== null) {
                $productCount++;
                yield $dto;
            }
        }

        $reader->close();

        Yii::info("Ormatek: парсинг завершён — items={$itemCount} products={$productCount} errors={$this->stats['errors']}", 'import');
    }

    public function estimateCount(string $filePath): ?int
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false) return null;
        return (int)(($fileSize / 2048) / 20);
    }

    protected function parsePriceItem(string $xml): ?array
    {
        try {
            $node = @simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
        } catch (\Exception $e) {
            return null;
        }

        if ($node === false) return null;

        $modelName = $this->cleanString((string)($node->{'model-name'} ?? ''));
        $brandName = $this->cleanString((string)($node->{'brand-name'} ?? ''));
        $productCode = $this->cleanString((string)($node->{'product-code'} ?? ''));

        if (empty($modelName) || empty($productCode)) return null;

        // Цена + акции
        $baseRetailPrice = (float)($node->{'base-retail-price'} ?? 0);
        $baseDealerPrice = (float)($node->{'base-dealer-price'} ?? 0);
        $price = $baseRetailPrice;
        $oldPrice = null;

        if (isset($node->actions) && $node->actions->action) {
            $now = time();
            foreach ($node->actions->action as $action) {
                $discounted = (float)($action->{'discounted-retail-price'} ?? 0);
                if ($discounted <= 0) continue;

                $dateTill = trim((string)($action->{'date-till'} ?? ''));
                if (!empty($dateTill)) {
                    $endTs = strtotime($dateTill);
                    if ($endTs !== false && $endTs < $now) continue;
                }

                $price = $discounted;
                $oldPrice = $baseRetailPrice;
                break;
            }
        }

        if ($oldPrice !== null && (abs($price - $oldPrice) < 0.01 || $oldPrice <= 0)) {
            $oldPrice = null;
        }

        // Изображения
        $images = [];
        if (isset($node->pictures) && $node->pictures->picture) {
            foreach ($node->pictures->picture as $picture) {
                $url = $this->cleanString((string)$picture);
                if ($url) $images[] = $url;
            }
        }

        // Размер
        $width = (int)($node->width ?? 0);
        $length = (int)($node->length ?? 0);
        $height = (float)($node->height ?? 0);
        $size = ($width > 0 && $length > 0) ? "{$width}x{$length}" : null;

        $disabled = (string)($node->disabled ?? 'false') === 'true';

        return [
            'model_name' => $modelName,
            'brand_name' => $brandName,
            'product_line' => $this->cleanString((string)($node->{'product-line'} ?? '')),
            'description' => $this->cleanString((string)($node->description ?? '')),
            'decor_name' => $this->cleanString((string)($node->{'decor-name'} ?? '')),
            'decor_type' => $this->cleanString((string)($node->{'decor-type'} ?? '')),
            'product_name' => $this->cleanString((string)($node->{'product-name'} ?? '')),
            'product_code' => $productCode,
            'product_uuid' => $this->cleanString((string)($node->{'product-uuid'} ?? '')),
            'width' => $width,
            'length' => $length,
            'height' => $height,
            'size' => $size,
            'weight' => (float)($node->{'product-weight'} ?? 0),
            'base_dealer_price' => $baseDealerPrice,
            'base_retail_price' => $baseRetailPrice,
            'price' => $price,
            'old_price' => $oldPrice,
            'disabled' => $disabled,
            'in_stock' => !$disabled,
            'images' => $images,
        ];
    }

    protected function buildProductDTO(array $items, bool $skipImages, int $maxImages): ?ProductDTO
    {
        if (empty($items)) return null;

        $first = $items[0];
        $modelName = $first['model_name'];
        $brandName = $first['brand_name'];
        $categoryPath = $first['product_line'] ?? '';
        $description = $first['description'] ?? '';

        // Уникальные изображения
        $allImages = [];
        if (!$skipImages) {
            $seenImages = [];
            foreach ($items as $item) {
                foreach ($item['images'] as $url) {
                    $key = basename(parse_url($url, PHP_URL_PATH) ?? $url);
                    if (!isset($seenImages[$key])) {
                        $seenImages[$key] = true;
                        $allImages[] = $url;
                    }
                }
            }
            if ($maxImages > 0 && count($allImages) > $maxImages) {
                usort($allImages, function ($a, $b) {
                    $scoreA = str_contains($a, 'ormatek.com') ? 0 : 2;
                    $scoreB = str_contains($b, 'ormatek.com') ? 0 : 2;
                    return $scoreA <=> $scoreB;
                });
                $allImages = array_slice($allImages, 0, $maxImages);
            }
        }

        // Общие атрибуты модели
        $attributes = [];
        if ($first['height'] > 0) {
            $attributes['Высота'] = $first['height'] . ' см';
        }

        // Варианты
        $variants = [];
        $activeCount = 0;
        foreach ($items as $item) {
            $options = [];
            if ($item['size']) $options['Размер'] = $item['size'];
            if ($item['decor_name']) $options['Декор'] = $item['decor_name'];
            if ($item['decor_type']) $options['Тип декора'] = $item['decor_type'];

            $isActive = $item['in_stock'] && $item['price'] > 0;
            if ($isActive) $activeCount++;

            $variants[] = new VariantDTO(
                sku: $item['product_code'],
                price: $item['price'],
                comparePrice: $item['old_price'],
                inStock: $isActive,
                stockQuantity: null,
                stockStatus: $isActive ? 'available' : ($item['disabled'] ? 'discontinued' : 'out_of_stock'),
                options: $options,
            );
        }

        $productInStock = $activeCount > 0;
        $supplierSku = $first['product_uuid'] ?: ($brandName . '::' . $modelName);

        return new ProductDTO(
            supplierSku: $supplierSku,
            name: $modelName,
            categoryPath: $categoryPath,
            manufacturer: $brandName ?: 'Орматек',
            brand: $brandName ?: null,
            model: $modelName,
            description: $description ?: null,
            price: null,
            inStock: $productInStock,
            stockStatus: $productInStock ? 'available' : 'out_of_stock',
            attributes: $attributes,
            imageUrls: $allImages,
            variants: $variants,
            rawData: [
                'brand' => $brandName,
                'product_line' => $categoryPath,
                'first_uuid' => $first['product_uuid'],
                'variant_count' => count($variants),
                'active_variants' => $activeCount,
            ],
        );
    }
}
