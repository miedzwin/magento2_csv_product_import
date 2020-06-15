# magento2_csv_product_import

This is only example code of import products into Magento 2.3.3 and older.

First of all you need to import categories, for this use `bulkcategoryimport.php` you can find a lot of explanaitions on internet how to import categories in Magento 2.

If you don't know how to import sizes from CSV, take a look at `importsizes.php` file. When I was importing products I had a lot of them with different sizes (custom attributes, not default ones you have while creating a product). So I prepare script that is creating custom attribute for me.

Last of the scripts `importproducts.php` was the most difficult to create. I had no experience in Magento 2 and it was to find good tutorial to import products with multiple variations (different color, size etc.) also I had to create tier prices manually, because in my case I had 3500 products with them.
Take a look at products CSV file, you can notice that `Advanced pricing` column contains JSON. So script looks also there if there is an empty array - it creates `Simple product` if there is data it creates `Configurable product` and after that all possible variations for that product with tier prices for each. I know that this may be difficult to understand, but this was second or even third attempt to create that script and it runs once, so use this script like an example to create yours, but in case of functionality, not mess I created.
