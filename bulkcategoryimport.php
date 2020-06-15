<?php

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;

include 'app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

$objectManager = ObjectManager::getInstance();
$storeManager = $objectManager->get(StoreManagerInterface::class);
$storeId = $storeManager->getStore()->getStoreId();
$categories = getCategories();
importCategories($categories, $objectManager, $storeId);

/**
 * @return array
 */
function getCategories()
{
    $file = './script/csv/categories.csv';
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
 * @param string $imgPath
 * @param string $imgName
 */
function copyCategoryImage(string $imgPath, string $imgName)
{
    if (!$imgPath || !$imgName) {
        return;
    }

    $srcImgPath = '';
    $basePath = './script/images/';
    $destImgPath = './pub/media/catalog/category/';
    if ($imgPath[0] === '/') {
        $srcImgPath = $basePath . substr($imgPath, 1);
    } else {
        $srcImgPath = $basePath . $imgPath;
    }

    if (file_exists($srcImgPath)) {
        copy($srcImgPath, $destImgPath . $imgName);
    }
}

/**
 * @param array $arrResult
 * @param $objectManager
 * @param string $storeId
 * @return array
 */
function importCategories(array $arrResult, $objectManager, string $storeId)
{
    $parentId = 2;
    $list = [];

    foreach ($arrResult as $import_category) {
        $imageSrcPath = $import_category[6];
        $imgSrcArr = explode('/', $imageSrcPath);
        $imgName = end($imgSrcArr);
        try {
            $enabled = (strtolower($import_category[16]) == 'true') ? 1 : 0;
            $parentId = ($import_category[1] == 0) ? 2 : $list[$import_category[1]];

            $url = strtolower($import_category[2]);
            $url = iconv('UTF-8', 'ASCII//TRANSLIT', $url);
            $cleanurl = trim(preg_replace('/ +/', '', preg_replace('/[^A-Za-z0-9 ]/', '', urldecode(html_entity_decode(strip_tags($url))))));
            $categoryFactory = $objectManager->get('\Magento\Catalog\Model\CategoryFactory');
            /// Add a new sub category under root category
            $category = $categoryFactory->create();
            $category->setName(ucfirst($import_category[2]));
            $category->setIsActive($enabled);
            //check for duplicate URL - add 'copy' if duplicated
            $existUrl = $categoryFactory->create()->getCollection()->addFieldToFilter('url_key', ['in' => $cleanurl]);
            if ($existUrl->getSize() != 0) {
                $cleanurl .= '-copy' . rand(1, 100);
            }
            $category->setUrlKey($cleanurl);
            $category->setData('description', strip_tags($import_category[11]));
            $category->setMetaTitle(trim($import_category[5]));
            $category->setMetaDescription(trim(strip_tags($import_category[6])));
            $category->setMetaKeyword(trim($import_category[7]));
            $category->setParentId($parentId);
            $mediaAttribute = ['image', 'small_image', 'thumbnail'];
            $category->setImage(trim($imgName), $mediaAttribute, true, false);
            $category->setStoreId($storeId);
            $rootCat = $objectManager->get('Magento\Catalog\Model\Category')->load($parentId);
            $category->setPath($rootCat->getPath());
            $category->save();
            $list[$import_category[0]] = $category->getId();

            // only if category was imported - copy image to new path
            copyCategoryImage($imageSrcPath, $imgName);
            echo 'Category ' . $category->getName() . ' ' . $category->getId() . ' imported successfully' . PHP_EOL;
        } catch (Exception $e) {
            echo 'Something failed for category ' . $import_category[2] . PHP_EOL;
            var_dump($e->getFile());
            var_dump($e->getLine());
            die(var_dump($e->getMessage()));
        }
    }
    return $list;
}
