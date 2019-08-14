<?php

use IR_Neatly_Exception_Api as ApiException;

class IR_Neatly_Model_Reports_Reporting extends IR_Neatly_Model_Reports_Abstract
{
    /**
     * Get customers.
     *
     * @return mixed
     */
    public function getCustomers($count = false, $options = array())
    {
        $options = array_merge(array(
            'status' => 'complete',
            'item_total' => null,
            'item_total_operator' => null,
            'order_value' => null,
            'order_value_operator' => null,
            'sku' => null,
            'customer_email' => null,
            'city' => null,
            'region' => null,
            'postcode' => null,
            'country_id' => null,
        ), $this->options, $options);

        // if asking for "show me customers who have not placed an order between these dates".
        if ((in_array($options['item_total_operator'], array('=', '<', '<=')) && $options['item_total'] === '0') ||
            (in_array($options['order_value_operator'], array('=', '<', '<=')) && $options['order_value'] === '0')) {
            return $this->getCustomersWithoutOrder($count, $options);
        }

        // new query.
        $query = $this->readConnection->select();

        // get table names.
        $customersTbl = $this->resource->getTableName('customer_entity');
        $addressTbl = $this->resource->getTableName('sales/order_address');
        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $itemsTbl = $this->resource->getTableName('sales/order_item');

        if ($count) {
            $columns = array(
                'total' => 'COUNT(DISTINCT(sfo.customer_email))',
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);
            $query->joinLeft(array('sfoa' => $addressTbl), 'sfoa.entity_id = sfo.billing_address_id', array());
        } else {
            $columns = array(
                'customer_email' => 'DISTINCT(customer_email)',
                'customer_firstname',
                'customer_lastname',
                'customer_middlename',
                'customer_prefix',
                'customer_suffix',
                'customer_is_guest',
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);

            $columns = array(
                'street',
                'city',
                'region',
                'postcode',
                'country_id',
                'telephone',
            );

            $query->joinLeft(array('sfoa' => $addressTbl), 'sfoa.entity_id = sfo.billing_address_id', $columns);

            $query->limitPage($options['page'], $options['page_size']);
        }

        # $query->where('sfo.store_id = ?', $this->options['store_id']);

        if ($options['status']) {
            $query->where('status = ?', $options['status']);
        }

        if ($options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['order_value']) {
            $operator = $this->validOperator($options['order_value_operator']);
            $query->where("sfo.grand_total {$operator} ?", $options['order_value']);
        }

        if ($options['item_total']) {
            $operator = $this->validOperator($options['item_total_operator']);
            $query->where("sfo.total_qty_ordered {$operator} ?", $options['item_total']);
        }

        if ($options['customer_email']) {
            $query->where('sfo.customer_email = ?', $options['customer_email']);
        }

        if ($options['city']) {
            $query->where('sfoa.city = ?', $options['city']);
        }

        if ($options['region']) {
            $query->where('sfoa.region = ?', $options['region']);
        }

        if ($options['postcode']) {
            $query->where('sfoa.postcode = ?', $options['postcode']);
        }

        if ($options['country_id']) {
            $query->where('sfoa.country_id = ?', $options['country_id']);
        }

        if ($options['sku']) {
            // if sku is not an array.
            if (!is_array($options['sku'])) {
                $options['sku'] = array($options['sku']);
            }

            $marks = array();
            foreach ($options['sku'] as $sku) {
                $marks[] = "?";
            }

            $in = implode(',', $marks);

            $sql = "EXISTS (SELECT
                        sku
                     FROM
                        {$itemsTbl} i
                    WHERE
                        i.order_id = sfo.entity_id
                        AND parent_item_id IS NULL
                        AND sku IN ({$in}))";

            $query->where($sql, $options['sku']);
        }

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0]['total'];
        } else {
            return $data;
        }
    }

    /**
     * Search for customers who have not placed an order.
     *
     * @param bool $count
     * @param array $options
     * @return int|array.
     */
    protected function getCustomersWithoutOrder($count = false, $options = array())
    {
        $c = Mage::getSingleton('core/resource')->getConnection('default_write');

        $onWhere = array('sfo.status = "complete"');

        if ($options['from']) {
            $onWhere[] = sprintf('DATE(sfo.created_at) >= %s', $c->quote($this->options['from']));
        }

        if ($options['to']) {
            $onWhere[] = sprintf('DATE(sfo.created_at) <= %s', $c->quote($this->options['to']));
        }

        $collection = Mage::getResourceModel('customer/customer_collection')
            ->addAttributeToSelect('customer_id')
            ->addNameToSelect()
            ->addAttributeToSelect('email')
            ->addAttributeToSelect('created_at')
            ->addAttributeToSelect('group_id')
            ->joinAttribute('billing_postcode', 'customer_address/postcode', 'default_billing', null, 'left')
            ->joinAttribute('billing_city', 'customer_address/city', 'default_billing', null, 'left')
            ->joinAttribute('billing_telephone', 'customer_address/telephone', 'default_billing', null, 'left')
            ->joinAttribute('billing_region', 'customer_address/region', 'default_billing', null, 'left')
            ->joinAttribute('billing_country_id', 'customer_address/country_id', 'default_billing', null, 'left')
            ->joinTable(
                array('sfo' => 'sales/order'),
                'customer_id = entity_id',
                array('customer_id'),
                implode(" AND ", $onWhere) ?: null,
                'left'
            )
            ->distinct(true);

        $collection->getSelect()->where('sfo.customer_id IS NULL');

        if ($options['customer_email']) {
            $collection->addAttributeToFilter('email', array('eq' => $options['customer_email']));
        }

        if ($options['city']) {
            $collection->addAttributeToFilter('billing_city', array('eq' => $options['city']));
        }

        if ($options['region']) {
             $collection->addAttributeToFilter('billing_region', array('eq' => $options['region']));
        }

        if ($options['postcode']) {
            $collection->addAttributeToFilter('billing_postcode', array('eq' => $options['postcode']));
        }

        if ($options['country_id']) {
            $collection->addAttributeToFilter('billing_country_id', array('eq' => $options['country_id']));
        }

        if ($count) {
            return $collection->getSize();
        }

        // set limit.
        $collection->getSelect()->limit($options['page_size'], ($options['page_size'] * ($options['page'] - 1)));

        $customers = array();

        foreach ($collection as $customer) {
            $customers[] = array(
                'customer_email' => isset($customer['email']) ? $customer['email'] : null,
                'customer_firstname' => isset($customer['firstname']) ? $customer['firstname'] : null,
                'customer_lastname' => isset($customer['lastname']) ? $customer['lastname'] : null,
                'customer_middlename' => isset($customer['middlename']) ? $customer['middlename'] : null,
                'customer_prefix' => isset($customer['prefix']) ? $customer['prefix'] : null,
                'customer_suffix' => isset($customer['suffix']) ? $customer['suffix'] : null,
                'customer_is_guest' => 0,
                'street' => isset($customer['billing_street']) ? $customer['billing_street'] : null,
                'city' => isset($customer['billing_city']) ? $customer['billing_city'] : null,
                'region' => isset($customer['billing_region']) ? $customer['billing_region'] : null,
                'postcode' => isset($customer['billing_postcode']) ? $customer['billing_postcode'] : null,
                'country_id' => isset($customer['billing_country_id']) ? $customer['billing_country_id'] : null,
                'telephone' => isset($customer['telephone']) ? $customer['telephone'] : '',
            );
        }

        return $customers;
    }

    /**
     * Get sales statistics by type.
     *
     * @param array $options
     * @return array
     */
    public function getSalesByTypeStats($options = array())
    {
        $options = array_merge(array(
            'sku' => '',
            'category' => '',
            'manufacturer' => '',
            'date_format' => 'Y-m-d',
            'status' => 'complete',
            'group' => false,
            'categories' => array(),
            'attributes' => array(),
        ), $this->options, $options);

        $c = Mage::getSingleton('core/resource')->getConnection('default_write');

        $onWhere = array();

        if ($options['from']) {
            $onWhere[] = sprintf('DATE(sfoi.created_at) >= %s', $c->quote($this->options['from']));
        }

        if ($options['to']) {
            $onWhere[] = sprintf('DATE(sfoi.created_at) <= %s', $c->quote($this->options['to']));
        }

        $collection = Mage::getModel('catalog/product')->getCollection()
            ->joinTable(
                array('sfoi' => 'sales/order_item'),
                'product_id = entity_id',
                array('p_id' => 'product_id'),
                implode(" AND ", $onWhere) ?: null
            );


        $query = $collection->getSelect();

        // if sku set.
        if ($options['sku']) {
            #$query->where('sfoi.sku = ?', $options['sku']);
            $query->where('sfoi.sku LIKE ?', "{$options['sku']}%");
        } else {
            // join any attributes and categories set.
            $this->joinAttributes($query, $options['attributes'])
                 ->joinCategories($query, $options['categories']);
        }

        if ($options['status']) {
            $salesOrderTbl = $this->resource->getTableName('sales/order');
            $query->join(array('sfo' => $salesOrderTbl), 'sfo.entity_id = sfoi.order_id', array())
                  ->where('`sfo`.`status` = ?', $options['status']);
        }

        $columns = array(
            'total_count' => 'SUM(`sfoi`.`qty_ordered`)',
            'total_value' => 'SUM(`sfoi`.`row_total_incl_tax`)',
        );

        if ($options['group']) {
            $columns['date'] = sprintf('DATE_FORMAT(`sfoi`.`created_at`, "%s")', $options['date_format']);
            $query->group($columns['date']);
        }

        // reset columns.
        $query->reset(Zend_Db_Select::COLUMNS)
              ->columns($columns);

        $data = $collection->getData();

        if (!$options['group']) {
            return array(
                'total_count' => $data[0]['total_count'] ?: 0,
                'total_value' => $data[0]['total_value'] ?: 0,
            );
        }

        $dateRange = $this->getDates($options['from'], $options['to'], $options['group_by']);

        return $this->datesCombine($dateRange, $data, array_keys($columns));
    }

    /**
     * Get an array of attributes and their acceptable values.
     *
     * @return array
     */
    public function getProductAttributes()
    {
        $query = Mage::getResourceModel('catalog/product_attribute_collection')->getSelect();

        $query->where('frontend_input = ?', 'select')
              ->order('frontend_label ASC');

        $columns = array(
            'id' => 'attribute_id',
            'code' => 'attribute_code',
            'label' => 'frontend_label',
        );

        // reset columns.
        $query->reset(Zend_Db_Select::COLUMNS)
              ->columns($columns);

        return $this->readConnection->fetchAll($query);
    }

    /**
     * Get a product attribute and a collection of it's acceptable values.
     *
     * @param int $id
     * @return stdClass
     */
    public function getProductAttribute($code)
    {
        $attr = $this->getAttribute($code);

        return (object)array(
            'id' => $attr->getAttributeId(),
            'code' => $attr->getAttributeCode(),
            'label' => $attr->getFrontendLabel(),
            'options' => $attr->getSource()->getAllOptions(false),
        );
    }

    /**
     * Get product categories.
     *
     * @param array $options
     * @return array
     */
    public function getCategories($options = array())
    {
        $options = array_merge(array(
            'store_id' => null,
        ), $this->options, $options);

        // new query.
        $query = $this->readConnection->select();

        $cceTable = $this->resource->getTableName('catalog/category');
        $ccevTable = Mage::getConfig()->getTablePrefix() . 'catalog_category_entity_varchar';
        // get category name attribute id.
        $attributeId = $this->getCategoryNameAttributeId();

        $columns = array(
            'id' => 'entity_id',
            'level',
        );

        $query->from(array('cce' => $cceTable), $columns)
              ->join(
                    array('ccev' => $ccevTable),
                    implode(' AND ', array(
                        "`ccev`.`entity_id` = `cce`.`entity_id`",
                        "`ccev`.`attribute_id`={$attributeId}",
                        sprintf("`ccev`.`store_id` = %d", (int)$options['store_id'])
                    )),
                    array('name' => 'value')
                )
              ->order('path ASC');

        $cats = $this->readConnection->fetchAll($query);

        return $this->buildCatTree($cats);
    }

    /**
     * Get product by sku.
     *
     * @param string $sku
     * @return stdClass|null
     */
    public function getProductBySku($sku)
    {
        if (!$product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku)) {
            // throw new ApiException(sprintf('Product "%s" not found', $sku), 400);
            return "404";
        }

        return array(
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $product->getPrice(),
            'short_description' => $product->getShortDescription(),
            'url' => $product->getProductUrl(),
            'img' => array(
                'regular' => $product->getImageUrl(),
                'thumbnail' => $product->getThumbnailUrl()
            )
        );
    }

    /**
     * Build category tree.
     *
     * @param array $cats
     * @return array
     */
    protected function buildCatTree(&$cats)
    {
        $cat = (object)array_shift($cats);

        $cat->children = array();

        if (isset($this->lastCat[$cat->level - 1])) {
            $this->lastCat[$cat->level - 1]->children[] = $cat;
        } else {
            $this->tree[] = $cat;
        }

        $this->lastCat[$cat->level] = $cat;

        if ($cats) {
            $this->buildCatTree($cats);
        }

        return $this->tree;
    }

    /**
     * Check to see whether the passed comparison operator is valid.
     *
     * @param string $operator
     * @return string
     */
    protected function validOperator($operator)
    {
        $operators = array(
            ">",
            ">=",
            "<=",
            "=",
            "!="
        );

        // if operator is not valid.
        if (!in_array($operator, $operators)) {
            // return default operator.
            return '=';
        }

        return $operator;
    }


    /**
     * Join categories to select query.
     *
     * @param Varien_Db_Select $query
     * @param array $categories
     * @return self
     */
    protected function joinCategories($query, $categories = array())
    {
        // if no categories set.
        if (!is_array($categories) || empty($categories)) {
            return $this;
        }

        // get table names.
        $ccpTable = $this->resource->getTableName('catalog/category_product');
        $cceTable = $this->resource->getTableName('catalog/category');
        $ccevTable = Mage::getConfig()->getTablePrefix() . 'catalog_category_entity_varchar';
        // get "category name" attribute id.
        $attributeId = $this->getCategoryNameAttributeId();

        $query->join(array('ccp' => $ccpTable), '`ccp`.`product_id` = `e`.`entity_id`', array())
              ->join(array('cce' => $cceTable), '`cce`.`entity_id` = `ccp`.`category_id`', array())
              ->join(
                    array('ccev' => $ccevTable),
                    "`ccev`.`entity_id` = `cce`.`entity_id` AND `ccev`.`attribute_id`={$attributeId}",
                    array()
                )
              ->where('`ccp`.`category_id` IN(?)', $categories);

        return $this;
    }

    /**
     * Join attributes to select query.
     *
     * @param Varien_Db_Select $query
     * @param array $attributes
     * @return self
     */
    protected function joinAttributes($query, $attributes = array())
    {
        $c = Mage::getSingleton('core/resource')->getConnection('default_write');

        $attributes = is_array($attributes) ? $attributes : array();

        foreach ($attributes as $code => $value) {
            // escape value
            $value = $c->quote($value);

            // get attriubte.
            $attr = $this->getAttribute($code);
            $alias = "{$code}_table";
            $aliasEaov = "{$alias}_eaov";

            // join attribute
            $query->join(
                array($alias => $attr->getBackendTable()),
                "product_id = {$alias}.entity_id AND {$alias}.attribute_id={$attr->getId()}",
                array($code => 'value')
            );

            $query->join(
                array($aliasEaov => 'eav_attribute_option_value'),
                sprintf(
                    "{$aliasEaov}.option_id = {$alias}.value AND {$aliasEaov}.value = %s AND {$aliasEaov}.store_id = %d",
                    $value,
                    $this->options['store_id']
                ),
                array()
            );
        }

        return $this;
    }

    /**
     * Get an attribute.
     *
     * @param string $code
     * @return Mage_Catalog_Model_Resource_Eav_Attribute
     * @throws IR_Neatly_Exception_Api
     */
    protected function getAttribute($code)
    {
        $attr = Mage::getSingleton('eav/config')
                    ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $code);

        // if attribute does not exist.
        if (!$attr || !$attr->getId()) {
            throw new ApiException(sprintf('"%s" is not a valid attribute.', $code), 400);
        }

        $attr->setStoreId($this->options['store_id']);

        return $attr;
    }

    /**
     * Get the ID of the category name attribute.
     *
     * @return int
     */
    protected function getCategoryNameAttributeId()
    {
        $eavAttributeTbl = $this->resource->getTableName('eav/attribute');
        $eavEntityTypeTbl = $this->resource->getTableName('eav/entity_type');
        $query = $this->readConnection->select();
        $query->from(array('eat' => $eavEntityTypeTbl), array());
        $query->join(array('ea' => $eavAttributeTbl), 'ea.entity_type_id = eat.entity_type_id', array('attribute_id'));
        $query->where('eat.entity_type_code = ?', 'catalog_category');
        $query->where('ea.attribute_code = ?', 'name');

        $data = $this->readConnection->fetchAll($query);

        if (!isset($data[0]['attribute_id'])) {
            throw new Exception("Could not find category name attribute ID.");
        }

        return (int)$data[0]['attribute_id'];
    }
}
