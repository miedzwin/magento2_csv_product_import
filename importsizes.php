<?php


use Magento\Catalog\Api\Data\ProductTierPriceInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManager;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

include 'app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$appState = $objectManager->get('\Magento\Framework\App\State');
$appState->setAreaCode('frontend');

$data = getData();
foreach ($data as $size) {
    prepareProductSizeOptions($objectManager, $size[3]);
}

/**
 * @return array
 */
function getData()
{
    $file = './script/csv/sizes.csv';
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
 * @param string $data
 * @param string $modification
 * @return array
 */
function prepareProductSizeOptions($objectManager, string $data, $modification = 'size')
{
    $eavConfig = $objectManager->get(Config::class);
    $attribute = $eavConfig->getAttribute('catalog_product', $modification);

    $sizesAttrArr[] = trim($data);

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

    foreach ($sizesAttrArr as $option) {
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

    return $sizesAttrArr;
}