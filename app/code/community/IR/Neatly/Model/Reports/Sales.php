<?php

class IR_Neatly_Model_Reports_Sales extends IR_Neatly_Model_Reports_Abstract
{
    /**
     * Get total orders for a given date range aggregated by status.
     *
     * @return mixed
     */
    public function getTotalOrders()
    {
        $options = array_merge(array(
            'status' => 'complete',
        ), $this->options);

        // new query.
        $query = $this->readConnection->select();

        $columns = array(
            'total_orders' => 'COUNT(*)',
            'total_orders_value' => 'SUM(grand_total)',
            'date' => 'created_at'
        );

        if ($this->options['from']) {
            $query->where('DATE(created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('status = ?', $options['status']);
        }

        $query->group("DATE_FORMAT(`created_at`, '{$options['date_format']}')");

        $query->where('store_id = ?', $this->options['store_id']);

        $salesOrderTbl = $this->resource->getTableName('sales/order');

        $query->from($salesOrderTbl, $columns);

        $data = $this->readConnection->fetchAll($query);

        $dateRange = $this->getDates($options['from'], $options['to'], $options['group_by']);

        return $this->datesCombine($dateRange, $data, array_keys($columns));
    }

    /**
     * Get aggregated total sales data.
     *
     * @return array
     */
    public function getAggregatedTotals()
    {
        $options = array_merge(array(
            'status' => 'complete',
        ), $this->options);

        // new query.
        $query = $this->readConnection->select();

        $columns = array(
            'total_orders' => 'COUNT(*)',
            'total_items_ordered' => 'SUM(total_qty_ordered)',
            'total_tax' => 'SUM(tax_amount) - SUM(tax_canceled)',
            #'total_tax_amount_actual' => 'SUM(tax_invoiced) - SUM(tax_refunded)',
            'total_discounts' => 'SUM(discount_amount) - SUM(discount_canceled)',
            #'total_discounts_actual' => 'SUM(discount_invoiced) - SUM(discount_refunded)',
            'total_shipping' => 'SUM(shipping_amount) - SUM(shipping_canceled)',
            #'total_shipping_actual' => 'SUM(shipping_invoiced) - SUM(shipping_refunded)',
            'total_revenue' => 'SUM(total_paid) - SUM(total_refunded)',
            'total_paid_amount' => 'SUM(total_paid)',
            'total_refunded' => 'SUM(total_refunded)',
            'total_cancelled' => 'SUM(total_canceled)',
            'date' => 'created_at'
        );

        $query = $this->readConnection->select();

        if ($this->options['from']) {
            $query->where('DATE(created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('status = ?', $options['status']);
        }

        $query->where('store_id = ?', $this->options['store_id']);

        $salesOrderTbl = $this->resource->getTableName('sales/order');

        $query->from($salesOrderTbl, $columns);

        $data = $this->readConnection->fetchAll($query);

        foreach ($data[0] as $key => $val) {
            if (!$data[0][$key]) {
                $data[0][$key] = 0;
            } elseif (is_numeric($data[0][$key])) {
                $data[0][$key] = floatval($data[0][$key]);
            }
        }

        return $data[0];
    }

    /**
     * Get best selling products.
     *
     * @return array
     */
    public function getBestSellingProducts($count = false)
    {
        $options = array_merge(array(
            'status' => 'complete'
        ), $this->options);

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $salesOrderItemTbl = $this->resource->getTableName('sales/order_item');

        if ($count) {
            $columns = array(
                'total' => 'COUNT(sfoi.product_id)',
                'total_value' => 'SUM(sfoi.price)'
            );

            $query->from(array('sfoi' => $salesOrderItemTbl), $columns);
            $query->join(array('sfo' => $salesOrderTbl), 'sfo.entity_id = sfoi.order_id', array());
        } else {

            // get totals.
            $totals = $this->getBestSellingProducts(true);

            $columns = array(
                'total' => 'COUNT(sfoi.item_id)',
                'total_percentage' => "ROUND((COUNT(sfoi.item_id) / {$totals['total']}) * 100, 2)",
                'total_value' => 'SUM(sfoi.price)',
                'name' => 'CONCAT(sfoi.name, " (", sfoi.sku, ")")',
            );

            $query->limitPage($this->options['page'], $this->options['page_size']);
            $query->from(array('sfoi' => $salesOrderItemTbl), $columns);
            $query->join(array('sfo' => $salesOrderTbl), 'sfo.entity_id = sfoi.order_id', array());
            $query->group("sfoi.product_id");
            $query->order('total DESC');
        }

        if ($this->options['from']) {
            $query->where('DATE(sfoi.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfoi.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0];
        } else {
            return $this->appendOther($totals, $data);
        }
    }

    /**
     * Get total orders by group type.
     *
     * @param array $options
     * @param bool $count
     * @return mixed
     */
    public function getOrdersByGroup($options, $count = false)
    {
        $options = array_merge(array(
            'status' => 'complete',
            'group' => 'sfoa.country_id'
        ), $this->options, $options);

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $salesOrderAddressTbl = $this->resource->getTableName('sales/order_address');
        $salesOrderPaymentTbl = $this->resource->getTableName('sales/order_payment');
        $query->join(array('sfoa' => $salesOrderAddressTbl), 'sfoa.parent_id = sfo.entity_id AND sfoa.address_type = "billing"', array());
        $query->join(array('sfop' => $salesOrderPaymentTbl), 'sfop.parent_id = sfo.entity_id', array());

        if ($count) {
            $columns = array(
                'total' => 'COUNT(*)',
                'total_value' => 'SUM(sfo.grand_total)'
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);

        } else {
            // get totals.
            $totals = $this->getOrdersByGroup($options, true);

            $columns = array(
                'total' => 'COUNT(*)',
                'total_percentage' => "ROUND((COUNT(sfo.entity_id) / {$totals['total']}) * 100, 2)",
                'total_value' => 'SUM(sfo.grand_total)',
                'name' => $options['group'],
            );

            $query->limitPage($this->options['page'], $this->options['page_size']);
            $query->from(array('sfo' => $salesOrderTbl), $columns);
            $query->group($options['group']);
            $query->order('total DESC');
        }

        if ($this->options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.status != ?', 'canceled');

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0];
        } else {
            return $this->appendOther($totals, $data);
        }
    }

    /**
     * Get total orders by group type.
     *
     * @param array $options
     * @param bool $count
     * @return mixed
     */
    public function getOrdersByPeriod($options, $count = false)
    {
        $options = array_merge(array(
            'status' => 'complete',
            'period' => 'hour'
        ), $this->options, $options);

        $periods = array(
            'hour',
            'dayname'
        );

        if (!in_array($options['period'], $periods)) {
            $options['period'] = 'hour';
        }

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');

        if ($count) {
            $columns = array(
                'total' => 'COUNT(*)',
                'total_value' => 'SUM(sfo.grand_total)'
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);

        } else {
            // get totals.
            $totals = $this->getOrdersByPeriod($options, true);

            $columns = array(
                'total' => 'COUNT(*)',
                'total_percentage' => "ROUND((COUNT(sfo.entity_id) / {$totals['total']}) * 100, 2)",
                'total_value' => 'SUM(sfo.grand_total)',
                'name' => "{$options['period']}(created_at)",
            );

            if ($options['period'] === 'hour') {
                $columns['name'] = "DATE_FORMAT(created_at, '%k')";
            }

            // limit to 24 results (covers 7 days, covers 24 hours)
            $query->limitPage($this->options['page'], 24);
            $query->from(array('sfo' => $salesOrderTbl), $columns);
            $query->group("name");
            $query->order('total DESC');
        }

        if ($this->options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.status != ?', 'canceled');

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0];
        } else {
            // if hour, get all 24 hours.
            if ($options['period'] === 'hour') {
                return $this->parseHours($data);
            }
            return $data;
        }
    }

    /**
     * Get total orders.
     *
     * @return int
     */
    public function getTotal()
    {
        $options = array_merge(array(
            'status' => null
        ), $this->options);

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $salesOrderItemTbl = $this->resource->getTableName('sales/order_item');

        $columns = array('total' => 'COUNT(*)');

        $query->from(array('sfoi' => $salesOrderItemTbl), $columns);
        $query->join(array('sfo' => $salesOrderTbl), 'sfo.entity_id = sfoi.order_id', array());

        if ($this->options['from']) {
            $query->where('DATE(sfoi.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfoi.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.status != ?', 'canceled');

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        return $data[0]['total'];
    }

    /**
     * Get detailed information about orders.
     *
     * @param bool $count
     * @return array|int
     */
    public function getDetailedOrders($count = false)
    {
        $options = array_merge(array(
            'entity_id' => null,
            'status' => null,
            'sort' => 'sfo.increment_id'
        ), $this->options);

        // get total orders.
        $total = $this->getTotal();

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $salesOrderAddressTbl = $this->resource->getTableName('sales/order_address');
        $customersTbl = $this->resource->getTableName('customer/entity');
        $customerGroupsTbl = $this->resource->getTableName('customer_group');

        if ($count) {
            $query->from(array('sfo' => $salesOrderTbl), array('total' => 'COUNT(DISTINCT sfo.entity_id)'));
            $query->join(array('sfoa' => $salesOrderAddressTbl), 'sfoa.parent_id = sfo.entity_id', array());
        } else {
            // order columns.
            $columns = array(
                'entity_id',
                'increment_id',
                'status',
                'created_at',
                'customer_email',
                'total_qty_ordered',
                'discount_amount',
                'shipping_amount',
                'tax_amount',
                'subtotal',
                'grand_total',
                'customer_is_guest',
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);

            // address columns.
            $columns = array(
                'region',
                'city',
                'country_id',
                'postcode'
            );

            $query->join(array('sfoa' => $salesOrderAddressTbl), 'sfoa.parent_id = sfo.entity_id', $columns);

            // left join customers table.
            $query->joinLeft(array('c' => $customersTbl), 'c.entity_id = sfoa.customer_id', array());
            $query->joinLeft(array('cg' => $customerGroupsTbl), 'cg.customer_group_id = c.group_id', array('customer_group_code'));

            $sorts = array(
                'sfo.increment_id',
                'sfo.created_at',
                'sfo.status',
            );

            if (!in_array($options['sort'], $sorts)) {
                $options['sort'] = 'increment_id';
            }

            $query->limitPage($this->options['page'], $this->options['page_size']);
            $query->order("{$options['sort']} {$options['order']}");
            $query->group('sfo.entity_id');
        }

        if ($options['entity_id']) {
            $query->where('sfo.entity_id = ?', $options['entity_id']);
        }

        if ($this->options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.status != ?', 'canceled');

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0]['total'];
        }

        return $data;
    }

    /**
     * Get items for a given order.
     *
     * @param int $orderId
     * @param return array
     */
    public function getOrderItems()
    {
        $options = array_merge(array(
            'entity_id' => null,
        ), $this->options);

        $query = $this->readConnection->select();

        $columns = array(
            'item_id',
            'sku',
            'name',
            'created_at',
            'qty_ordered',
            'base_price',
            'tax_amount',
            'tax_percent',
            'discount_amount',
            'discount_percent',
            'price_incl_tax'
        );

        $salesOrderItemsTbl = $this->resource->getTableName('sales/order_item');
        $query->from(array('sfoi' => $salesOrderItemsTbl), $columns);

        if ($this->options['entity_id']) {
            $query->where('sfoi.order_id = ?', $this->options['entity_id']);
        }

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        return $data;
    }

    /**
     * Get total orders by product category.
     *
     * @return array
     */
    public function getOrdersByProductCategory($count = false)
    {
        $options = array_merge(array(
            'status' => 'complete'
        ), $this->options);

        $query = $this->readConnection->select();

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $salesOrderItemTbl = $this->resource->getTableName('sales/order_item');
        $catalogCategoryProductTbl = $this->resource->getTableName('catalog/category_product');
        $catalogCategoryTbl = $this->resource->getTableName('catalog/category');

        if ($count) {
            $columns = array(
                'total' => 'COUNT(sfoi.item_id)',
                'total_value' => 'SUM(sfoi.price)'
            );
        } else {
            // get totals.
            $totals = $this->getOrdersByProductCategory(true);

            $columns = array(
                'total' => 'COUNT(sfoi.item_id)',
                'total_percentage' => "ROUND((COUNT(sfoi.item_id) / {$totals['total']}) * 100, 2)",
                'total_value' => 'SUM(sfoi.price)',
                'name' => 'CONCAT(sfoi.name, " (", sfoi.sku, ")")',
            );

            $query->limitPage($this->options['page'], $this->options['page_size']);
            $query->group('ccev.entity_id');
            $query->order('total DESC');
        }

        $query->from(array('sfoi' => $salesOrderItemTbl), $columns);
        $query->join(array('sfo' => $salesOrderTbl), 'sfo.entity_id = sfoi.order_id', array());
        $query->join(array('ccp' => $catalogCategoryProductTbl), 'ccp.product_id = sfoi.product_id', array());
        $query->join(array('cc' => $catalogCategoryTbl), 'cc.entity_id = ccp.category_id', array());
        $attributeId = $this->getCategoryNameAttributeId();

        $tblName = Mage::getConfig()->getTablePrefix() . 'catalog_category_entity_varchar';
        $query->join(array('ccev' => $tblName), "ccev.entity_id = cc.entity_id AND attribute_id={$attributeId}", array('name' => 'value'));

        if ($this->options['from']) {
            $query->where('DATE(sfoi.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfoi.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.status != ?', 'canceled');

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0];
        } else {
            return $this->appendOther($totals, $data);
        }
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

    /**
     * Get a distinct list of all the order statuses set in the database.
     *
     * @return array
     */
    public function getDistinctOrderStatuses()
    {
        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $query = $this->readConnection->select();
        $query->from($salesOrderTbl, array('status' => 'DISTINCT(status)'));

        $data = $this->readConnection->fetchAll($query);

        $statuses = array();

        foreach ($data as $status) {
            // if status not set.
            if (!$status['status']) {
                continue;
            }

            $statuses[] = (object)array(
                'name' => ucwords(str_replace('_', ' ', $status['status'])),
                'value' => $status['status']
            );
        }

        $query->where('store_id = ?', $this->options['store_id']);

        return $statuses;
    }

    /**
     * Get all Magento stores.
     *
     * @return array
     */
    public function getStores()
    {
        $mage = Mage::app();

        $stores = array();

        // if stores returned.
        if ($data = $mage->getStores()) {
            foreach ($data as $store) {
                if (!$store->getIsActive()) {
                    continue;
                }

                $stores[] = (object)array(
                    "id" => $store->store_id,
                    "name" => $store->getName()
                );
            }
        }

        return $stores;
    }

    /**
     * Get meta information about the store and options.
     *
     * @return stdClass
     */
    public function getMeta()
    {
        $store = Mage::getModel('core/store')
                     ->load($this->options['store_id']);

        $currencyCode = $store->getCurrentCurrencyCode();
        $currencySymbol = Mage::app()
                              ->getLocale()
                              ->currency($currencyCode)
                              ->getSymbol();

        return (object)array(
            'store' => (object)array(
                'id' => $this->options['store_id'],
                'name' => $store->getName(),
            ),
            'currency' => (object)array(
                'code' => $currencyCode,
                'symbol' => $currencySymbol
            )
        );
    }

    /**
     * Parse a set of 24 hours.
     *
     * @param array $objects
     * @return array
     */
    protected function parseHours($objects = array())
    {
        $hours = array();
        foreach ($objects as $object) {
            $hours[$object['name']] = $object;
        }

        $resp = array();
        foreach (range(0, 23) as $hour) {
            $resp[] = array(
                'total' => isset($hours[$hour]) ? $hours[$hour]['total'] : 0,
                'total_percentage' => isset($hours[$hour]) ? $hours[$hour]['total_percentage'] : 0,
                'total_value' => isset($hours[$hour]) ? $hours[$hour]['total_value'] : 0,
                'name' => sprintf('%02d:00', $hour)
            );
        }

        // sort by total desc.
        usort($resp, function ($a, $b) {
            return  $b['total'] - $a['total'];
        });

        return $resp;
    }
}
