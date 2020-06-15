<?php

use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManager;

include 'app/bootstrap.php';
require_once './script/vendor/shuchkin/simplexlsx/src/SimpleXLSX.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$appState = $objectManager->get('\Magento\Framework\App\State');
$appState->setAreaCode('frontend');
$rows = SimpleXLSX::parse('./script/csv/products_all_eng.xlsx');
$engProducts = $rows->rows();
unset($engProducts[0]);

$productsData = getProducts();
importSimpleProducts($objectManager, $productsData);

/**
 * @return array
 */
function getProducts()
{
    $rows = SimpleXLSX::parse('./script/csv/products_all.xlsx');
    $products = $rows->rows();
    unset($products[0]);
    return $products;
    $file = './script/csv/products.csv';
    $arrResult = [];
    $headers = false;
    $handle = fopen($file, "r");
    if (empty($handle) === false) {
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            if (!$headers) {
                $headers[] = $data;
            } else {
                $arrResult[] = $data;
            }
        }
        fclose($handle);
    }
    return $arrResult;
}

/**
 * @param $objectManager
 * @param $importProducts
 */
function importSimpleProducts($objectManager, $importProducts)
{
    /** @var ProductTierPriceInterfaceFactory $tierPriceFactory */
    $tierPriceFactory = $objectManager->get(ProductTierPriceInterfaceFactory::class);
    /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryFactory */
    $productRepositoryFactory = $objectManager->create(ProductRepositoryInterface::class);
    $eavConfig = $objectManager->get(Config::class);
    try {
        foreach ($importProducts as $importProduct) {
            if ($importProduct[1] == '') {
                continue;
            }
            $mainProductArr = $importProduct;
            $simpleProductsArr = [];
            $attributeValues = [];
            //create simple product with color
            if ($importProduct[19] !== '') {
                $colorArray = explode('@', $importProduct[19]);
                $importProduct['color'] = $colorArray[0];
            }
            if ($importProduct[20] !== '') {
                $importProduct['filters'] = recurrentlyPrepareFilters(json_decode($importProduct[20], true));
            }
            // create simple products - with size
            if ($importProduct[18] != '[]') {
                $sizesArr = prepareProductSizeOptions($objectManager, $importProduct[18]);
                $importProduct['tierPricesCount'] = count($sizesArr);
                $attribute = $eavConfig->getAttribute('catalog_product', 'size');
                $importProduct['sizeArr'] = $sizesArr;
                $importProduct['sizeOptions'] = $attribute->getOptions();
                // first option is always empty
                array_shift($importProduct['sizeOptions']);
                $sizesArr = json_decode($importProduct[18], true);
                // create simple products by given size options
                foreach ($importProduct['sizeOptions'] as $sizeOption) {
                    foreach ($sizesArr as $label => $productSize) {
                        $tierPrices = prepareTierPrice($tierPriceFactory, $label, $productSize);
                        // check if option label and prices key are same for creating properly tier prices
                        if (trim($tierPrices['name']) === trim($sizeOption['label'])) {
                            $importProduct['tierPrices'] = $tierPrices['prices'];
                            $importProduct['lowestPrice'] = $tierPrices['lowestPrice'];
                            $importProduct['attributeCode'] = $attribute->getAttributeCode();
                            $importProduct['attributeValue'] = $attribute->getAttributeValue();
                            $importProduct['atricleName'] = trim($tierPrices['name']);
                            $importProduct['modification'] = trim($tierPrices['name']);
                            $importProduct['option'] = $sizeOption;
                            $importProduct['size'] = prepareSizeArr($sizeOption['label']);
                            if (isset($importProduct['filters'])) {
                                if (count($importProduct['filters']) == 1 && count($sizesArr) == 1) {
                                    foreach ($importProduct['filters'] as $filter) {
                                        $importProduct['filter'] = $filter;
                                        createSimpleProduct($objectManager, $importProduct, Type::TYPE_SIMPLE);
                                    }
                                } else {
                                    foreach ($importProduct['filters'] as $filter) {
                                        $importProduct['filter'] = $filter;
                                        $simpleProductsArr[] = createSimpleProduct($objectManager, $importProduct, Type::TYPE_VIRTUAL);
                                    }
                                }
                            } else {
                                // if array contains one size - makes no sense to create configurable product
                                if (count($sizesArr) == 1) {
                                    createSimpleProduct($objectManager, $importProduct, Type::TYPE_SIMPLE);
                                } else {
                                    $simpleProductsArr[] = createSimpleProduct($objectManager, $importProduct, Type::TYPE_VIRTUAL);
                                }
                            }
                        }
                    }
                }
            } elseif (isset($importProduct['filters']) && count($importProduct['filters']) !== 1) { // check if product have filters without size
                foreach ($importProduct['filters'] as $filter) {
                    $importProduct['filter'] = $filter;
                    $simpleProductsArr[] = createSimpleProduct($objectManager, $importProduct, Type::TYPE_VIRTUAL);
                }
            }

            // when there are simple products  - create configurable one
            if (!empty($simpleProductsArr)) {
                $simpleProductIds = [];
                $configurableAttributesData = [];
                foreach ($simpleProductsArr as $simpleProduct) {
                    foreach ($simpleProduct as $simpleProductId => $attributesValuesArr) {
                        // collect all ids of the simple products
                        $simpleProductIds[] = $simpleProductId;
                        foreach ($attributesValuesArr as $attrValue) {
                            $existAttribute = false;
                            foreach ($configurableAttributesData as $key => $configData) {
                                if ($configData['attribute_id'] === $attrValue['attribute_id']) {
                                    $isAttr = false;
                                    foreach ($configurableAttributesData[$key]['values'] as $value) {
                                        if ($value['value_index'] === $attrValue['values']['value_index']) {
                                            $isAttr = true;
                                            break;
                                        }
                                    }
                                    if (!$isAttr) {
                                        $configurableAttributesData[$key]['values'][] = $attrValue['values'];
                                    }
                                    $existAttribute = true;
                                    break;
                                }
                            }
                            if (!$existAttribute) {
                                $configurableAttributesData[] = [
                                    'attribute_id' => $attrValue['attribute_id'],
                                    'code' => $attrValue['code'],
                                    'label' => $attrValue['label'],
                                    'position' => '0',
                                    'values' => [$attrValue['values']],
                                ];
                            }
                        }
                    }
                }
                // some products create same id twice - remove duplicates
                $simpleProductIds = array_unique($simpleProductIds);
                /** @var Factory $optionsFactory */
                $optionsFactory = $objectManager->create(Factory::class);
                $configurableProductId = createSimpleProduct($objectManager, $mainProductArr, Configurable::TYPE_CODE);
                $configurableProduct = $objectManager->create('Magento\Catalog\Model\Product')->load(array_key_first($configurableProductId));

                $configurableOptions = $optionsFactory->create($configurableAttributesData);
                $extensionConfigurableAttributes = $configurableProduct->getExtensionAttributes();
                $extensionConfigurableAttributes->setConfigurableProductOptions($configurableOptions);
                $extensionConfigurableAttributes->setConfigurableProductLinks($simpleProductIds);
                $configurableProduct->setExtensionAttributes($extensionConfigurableAttributes);
                $configurableProduct->setAssociatedProductIds($simpleProductIds);

                $productRepositoryFactory->save($configurableProduct);
            } else {
                if (isset($importProduct['filters'])) {
                    if (count($importProduct['filters']) == 1) {
                        foreach ($importProduct['filters'] as $filter) {
                            $importProduct['filter'] = $filter;
                            createSimpleProduct($objectManager, $importProduct, Type::TYPE_SIMPLE);
                        }
                    } else {
                        $tierPrices = prepareTierPrice($tierPriceFactory, $mainProductArr[18]);
                        $mainProductArr['tierPrices'] = $tierPrices['prices'] ?? null;
                        $mainProductArr['lowestPrice'] = $tierPrices['lowestPrice'] ?? null;
                        createSimpleProduct($objectManager, $mainProductArr, Type::TYPE_SIMPLE);
                    }
                } else {
                    createSimpleProduct($objectManager, $importProduct, Type::TYPE_SIMPLE);
                }
            }
        }
    } catch (Exception $e) {
        var_dump($e->getTraceAsString());
        var_dump($e->getLine());
        var_dump($e->getFile());
        die(var_dump($e->getMessage()));
    }
}

/**
 * @param ObjectManager $objectManager
 * @param array $data
 * @param string $productType
 * @return int|null
 */
function createSimpleProduct(ObjectManager $objectManager, array $data, $productType = Type::TYPE_SIMPLE)
{
    $eavConfig = $objectManager->get(Config::class);
    // get product name without whitespaces at end and begining
    $productName = trim($data[1]);
    $sku = trim($data[3]) . '-' . trim($data[0]);
    $urlKey = $productName . ' - ' . 'ISP' . $data[0];
    // prepare category
    $categories = getCategoriesFromStr($objectManager, $data[17]);
    // Prepare image to copy and assign to product
    $dir = $objectManager->get('Magento\Framework\App\Filesystem\DirectoryList');
    $mediaDirectory = $dir->getPath('media') . '/old/';
    $imageSrcPath = $data[8];
    $imgSrcArr = explode('/', $imageSrcPath);
    $imgName = end($imgSrcArr);
    $imgExt = substr($imgName, strrpos($imgName, '.'));
    $imgName = substr($imgName, 0, strrpos($imgName, '.'));
    if (strlen($imgName) > 90) {
        $imgName = substr($imgName, 0, 30);
    }
    $srcImgPath = copyProductImage($imageSrcPath, $mediaDirectory . cleanStr($imgName) . $imgExt);
    $lowestPrice = $data['lowestPrice'] ?? $data[4];
    $subProductType = $productType === Type::TYPE_VIRTUAL ? Type::TYPE_SIMPLE : $productType;
    $attributes = [];
    try {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $objectManager->create(Product::class);
        $product->isObjectNew(true);
        $product->setTypeId($subProductType)
            ->setWebsiteIds([1])
            ->setAttributeSetId(4)
            ->setName($productName)
            ->setUrlKey(cleanStr($urlKey))
            ->setCreatedAt(strtotime('now'))
            ->setCategoryIds($categories)
            ->setPrice($lowestPrice)
            ->setWeight($data[10])
            ->setCustomAttribute('ts_dimensions_length', (isset($data['size']) && $data['size']) ? $data['size']['length'] / 10 : 0)
            ->setCustomAttribute('ts_dimensions_width', (isset($data['size']) && $data['size']) ? $data['size']['width'] / 10 : 0)
            ->setCustomAttribute('ts_dimensions_height', (isset($data['size']) && $data['size']) ? $data['size']['height'] / 10 : 0)
            ->setShortDescription($data[2])
            ->setTaxClassId(0)
            ->setDescription($data[2])
            ->setMetaTitle(trim($data[14]))
            ->setMetaKeyword($data[16])
            ->setMetaDescription($data[15])
            ->setStatus((isset($data[7]) && $data[7] == 1) ? Status::STATUS_ENABLED : Status::STATUS_DISABLED)
            ->setStockData(
                [
                    'use_config_manage_stock' => 1,
                    'manage_stock' => 1,
                    'qty' => (int)$data[6],
                    'is_in_stock' => 1, // is availible in stock
                ]
            );

        // if tier prices are set - > add them
        if (isset($data['tierPrices'])) {
            if (isset($data['size'])) {
                $attribute = $eavConfig->getAttribute('catalog_product', 'size');
                foreach ($attribute->getOptions() as $option) {
                    if ($option->getLabel() === $data['modification']) {
                        $attributes[] =
                            [
                                'attribute_id' => $attribute->getId(),
                                'code' => $attribute->getAttributeCode(),
                                'label' => $attribute->getStoreLabel(),
                                'position' => '0',
                                'values' => [
                                    'label' => $option->getLabel(),
                                    'attribute_id' => $attribute->getId(),
                                    'value_index' => $option->getValue(),
                                ],
                            ];
                        if ($attribute->usesSource()) {
                            $avid = $attribute->getSource()->getOptionId($data['modification']);
                            $sku .= $productType != Configurable::TYPE_CODE ? ' - ' . $avid : '';
                            $product->setData($attribute->getAttributeCode(), $avid);
                        }
                    }
                }
                $product->setTierPrices($data['tierPrices']);
            }
        }

        if (isset($data['filter'])) {
            foreach ($data['filter'] as $key => $value) {
                $attribute = $eavConfig->getAttribute('catalog_product', $key);
                foreach ($attribute->getOptions() as $option) {
                    if ($option->getLabel() === $value) {
                        $attributes[] =
                            [
                                'attribute_id' => $attribute->getId(),
                                'code' => $attribute->getAttributeCode(),
                                'label' => $attribute->getStoreLabel(),
                                'position' => '0',
                                'values' => [
                                    'label' => $option->getLabel(),
                                    'attribute_id' => $attribute->getId(),
                                    'value_index' => $option->getValue(),
                                ],
                            ];
                        if ($attribute->usesSource()) {
                            $avid = $attribute->getSource()->getOptionId($value);
                            $sku .= $productType != Configurable::TYPE_CODE ? ' - ' . $avid : '';
                            $product->setData($attribute->getAttributeCode(), $avid);
                        }
                    }
                }
            }
        }

        $product->setSku($sku);
        switch ($productType) {
            case Type::TYPE_VIRTUAL:
                /**
                 * 1 => 'Not Visible Individually',
                 * 2 => 'Catalog',
                 * 3 => 'Search',
                 * 4 => 'Catalog, Search'*/
                $product->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE);
                break;
            case Configurable::TYPE_CODE:
            case Type::TYPE_SIMPLE:
                $product->setVisibility(Visibility::VISIBILITY_BOTH);
                break;
        }

        if (isset($data['option'])) {
            $product->setSize($data['option']->getValue());
        }

        if (file_exists($srcImgPath)) {
            $product->addImageToMediaGallery($srcImgPath, ['image', 'small_image', 'thumbnail'], false, false);
        }
        /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryFactory */
        $productRepositoryFactory = $objectManager->create(ProductRepositoryInterface::class);
        copyAdditionalImages($product, $mediaDirectory, $data[9]);
        try {
            $productRepositoryFactory->save($product);
        } catch (\Exception $e) {
            $sku = $sku . '-' . $data[0] . '-' . rand(0, 100);
            var_dump($sku);
            $product->setSku($sku);
            $productRepositoryFactory->save($product);
        }
        createTranslationForProduct($objectManager, $sku, $data);
        return [$productRepositoryFactory->get($sku)->getId() => $attributes];
    } catch (Exception $e) {
        var_dump('Id -> ' . $data[0]);
        echo 'Something failed for product import ' . $data[1] . PHP_EOL;
        var_dump($e->getFile());
        var_dump($e->getLine());
        die(var_dump($e->getMessage()));
    }
}

function createTranslationForProduct($objectManager, $sku, $data)
{
    $storeId = 2;
    /** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepositoryFactory */
    $productRepositoryFactory = $objectManager->create(ProductRepositoryInterface::class);
    $engProduct = $productRepositoryFactory->get($sku);
    foreach ($GLOBALS["engProducts"] as $row) {
        if (trim($data[0]) == trim($row[0])) {
            $engProduct = $engProduct->setStoreId($storeId)
                ->setShortDescription($row[2])
                ->setName(trim($row[1]))
                ->setDescription($row[2])
                ->setMetaTitle(trim($row[14]))
                ->setMetaKeyword($row[16])
                ->setMetaDescription($row[15])
                ->save();
            return;
        }
    }
}

/**
 * Create all possible product combinations
 * @param array $filters
 * @return array
 */
function recurrentlyPrepareFilters(array $filters)
{
    $result = [[]];
    foreach ($filters as $property => $property_values) {
        $tmp = [];
        foreach ($result as $result_item) {
            foreach ($property_values as $property_value) {
                if ($property_value == '') {
                    continue;
                }
                $tmp[] = array_merge($result_item, [$property => $property_value]);
            }
        }
        $result = $tmp;
    }
    return $result;
}

/**
 * @param $objectManager
 * @param string $data
 * @param string $modification
 * @return array
 */
function prepareProductSizeOptions($objectManager, string $data, $modification = 'size')
{
    $eavConfig = $objectManager->get(Config::class);
    $attribute = $eavConfig->getAttribute('catalog_product', $modification);
    $sizesAttrArr = json_decode($data, true);
    $existingMagentoAttributeOptions = [];
    foreach ($attribute->getSource()->getAllOptions() as $option) {
        $existingMagentoAttributeOptions[] = $option['label'];
    }
    $newOptions = [];
    $counter = 0;
    foreach ($attribute as $option) {
        if (!$option->getValue()) {
            continue;
        }
        if ($option->getLabel() instanceof \Magento\Framework\Phrase) {
            $label = trim($option->getText());
        } else {
            $label = trim($option->getLabel());
        }

        if ($label == '') {
            continue;
        }

        $existingMagentoAttributeOptions[] = $label;
        $newOptions['value'][$option->getValue()] = [$label, $label];
        $counter++;
    }

    foreach ($sizesAttrArr as $option => $value) {
        $option = trim($option);
        if ($option == '') {
            continue;
        }

        if (!in_array($option, $existingMagentoAttributeOptions)) {
            $newOptions['value']['option_' . $option] = [$option, $option];
        }
        $counter++;
    }

    try {
        if (count($newOptions)) {
            $attribute->setOption($newOptions)->save();
        }
    } catch (Exception $e) {
        die(var_dump($e->getMessage()));
    }

    return array_keys($sizesAttrArr);
}

/**
 * @param string $imgPath
 * @param string $destPath
 * @return string|void
 */
function copyProductImage(string $imgPath, string $destPath)
{
    if (!$imgPath || !$destPath) {
        return;
    }

    $basePath = './script/images/';

    if ($imgPath[0] === '/') {
        $srcImgPath = $basePath . substr($imgPath, 1);
    } else {
        $srcImgPath = $basePath . $imgPath;
    }

    if (file_exists($srcImgPath)) {
        copy($srcImgPath, $destPath);
    }

    return $destPath;
}

/**
 * @param $product
 * @param string $mediaDir
 * @param string $images
 */
function copyAdditionalImages($product, string $mediaDir, string $images)
{
    $imagesArr = explode(';', $images);

    $basePath = './script/images/';

    foreach ($imagesArr as $imgPath) {
        if ($imgPath == '') {
            continue;
        }
        $imgpart = explode('/', $imgPath);
        $imgName = end($imgpart);
        $imgExt = substr($imgName, strrpos($imgName, '.'));
        $imgName = substr($imgName, 0, strrpos($imgName, '.'));
        if (strlen($imgName) > 90) {
            $imgName = substr($imgName, 0, 30);
        }

        if ($imgPath[0] === '/') {
            $srcImgPath = $basePath . substr($imgPath, 1);
        } else {
            $srcImgPath = $basePath . $imgPath;
        }

        $imgDest = $mediaDir . cleanStr($imgName) . $imgExt;
        if (file_exists($srcImgPath)) {
            copy($srcImgPath, $imgDest);
        }

        if (file_exists($imgDest)) {
            $product->addImageToMediaGallery($imgDest, null, false, false);
        }
    }
}

/**
 * @param $tierPriceFactory
 * @param string $label
 * @param array $tierPriceArr
 * @return array
 */
function prepareTierPrice($tierPriceFactory, string $label, array $tierPriceArr = []): array
{
    $lowestPrice = 0;
    $tierPrices = [];
    $allFloatPrices = [];
    if (empty($tierPriceArr)) {
        return $tierPrices;
    }
    foreach ($tierPriceArr as $tier) {
        $tierArr = explode('-', $tier);
        $allFloatPrices[] = (float)$tierArr[1];
        $tierPrices[] = $tierPriceFactory->create(
            [
                'data' => [
                    'customer_group_id' => \Magento\Customer\Model\Group::CUST_GROUP_ALL,
                    'qty' => (int)$tierArr[0],
                    'value' => (float)$tierArr[1]
                ]
            ]
        );
    }
    $result =
        [
            'option' => null,
            'name' => null,
            'prices' => $tierPrices,
            'lowestPrice' => (float)max(array_values($allFloatPrices)),
        ];

    if (strpos($label, ';')) {
        $option = explode(';', $label);
        $result['option'] = $option[0];
        $result['name'] = $label;
    } else {
        $result['name'] = $label;
    }

    return $result;
}

/**
 * @param string $sizeStr
 * @return array
 */
function prepareSizeArr(string $sizeStr)
{
    $sizeArr = explode('x', $sizeStr);

    return [
        'length' => isset($sizeArr[0]) ? intval($sizeArr[0]) : null,
        'width' => isset($sizeArr[1]) ? intval($sizeArr[1]) : null,
        'height' => isset($sizeArr[2]) ? intval($sizeArr[2]) : null,
    ];
}

/**
 * @param $string
 * @return string|string[]|null
 */
function cleanStr($string)
{
    $string = str_replace(' ', '-', $string); // Replaces all spaces with hyphens.
    $string = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $string);
    $string = preg_replace('/[^A-Za-z0-9\-]/', '', $string); // Removes special chars.
    $string = strtolower($string);

    return preg_replace('/-+/', '-', $string); // Replaces multiple hyphens with single one.
}

/**
 * @param $objectManager
 * @param string $categories
 * @return array
 */
function getCategoriesFromStr($objectManager, string $categories)
{
    $categoriesIds = [];
    $categoriesArr = explode(';', $categories);
    $_categoryFactory = $objectManager->get(\Magento\Catalog\Model\CategoryFactory::class);

    foreach ($categoriesArr as $categoryName) {
        $collection = $_categoryFactory->create()->getCollection()->addFieldToFilter('name', ['in' => $categoryName]);

        if ($collection->getSize()) {
            $categoriesIds[] = $collection->getFirstItem()->getId();
        }
    }

    return $categoriesIds;
}
