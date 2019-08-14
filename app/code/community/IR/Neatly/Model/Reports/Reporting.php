<?php

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
}
