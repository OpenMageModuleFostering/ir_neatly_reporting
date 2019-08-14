<?php

class IR_Neatly_Model_Reports_Customers extends IR_Neatly_Model_Reports_Abstract
{
    /**
     * Get total new vs exsiting customers grouped by dates.
     *
     * @return array
     */
    public function getNewVsReturningCustomers()
    {
        $options = array_merge(array(
            'status' => 'complete'
        ), $this->options);

        $query = $this->readConnection->select();

        $columns = array(
            // where customer entity doesn't exist or difference between order and customer created date is 0
            'total_new' => 'SUM(IF(c.entity_id IS NULL OR DATEDIFF(sfo.created_at, c.created_at) = 0, 1, 0))',
            // where customer entity exist AND difference between order and customer created date is greater than 0
            'total_existing' => 'SUM(IF(c.entity_id IS NOT NULL AND DATEDIFF(sfo.created_at, c.created_at) > 0, 1, 0))',
        );

        // add period column
        $columns['date'] = 'DATE(sfo.created_at)';

        // add group by clause.
        # $query->group("{$options['group_by']}(sfo.created_at)");
        $query->group("DATE_FORMAT(sfo.created_at, '{$options['date_format']}')");

        if ($this->options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $query->from(array('sfo' => $salesOrderTbl), $columns);

        $customersTbl = $this->resource->getTableName('customer/entity');
        $query->joinLeft(array('c' => $customersTbl), 'c.entity_id = sfo.customer_id', array());

        $groups = $this->readConnection->fetchAll($query);

        $dateRange = $this->getDates($options['from'], $options['to'], $options['group_by']);

        return $this->datesCombine($dateRange, $groups, array_keys($columns));
    }

    /**
     * Find out how many customers have ordered a total number of items. For
     * example "2 customers ordered 35 products, 10 customers ordered 2
     * products, etc".
     *
     * @return array
     */
    public function getProductsPerCustomer()
    {
        $options = array_merge(array(
            'status' => 'complete'
        ), $this->options);

        $query = $this->readConnection->select();

        $columns = array(
            'total_qty_ordered',
            'total_customers' => 'COUNT(*)',
            'total_invoiced' => 'SUM(total_invoiced)',
            'total_refunded' => 'SUM(total_refunded)',
            'total' => 'IF(SUM(total_refunded), SUM(total_invoiced) - SUM(total_refunded), SUM(total_invoiced))'
        );

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $query->from(array('sfo' => $salesOrderTbl), $columns);

        if ($this->options['from']) {
            $query->where('DATE(sfo.created_at) >= ?', $this->options['from']);
        }

        if ($this->options['to']) {
            $query->where('DATE(sfo.created_at) <= ?', $this->options['to']);
        }

        if ($options['status']) {
            $query->where('sfo.status = ?', $options['status']);
        }

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $query->limitPage($this->options['page'], $this->options['page_size']);
        $query->order("total_qty_ordered {$options['order']}");
        $query->group('total_qty_ordered');

        $data = $this->readConnection->fetchAll($query);

        return $data;
    }

    /**
     * Get total orders by customer groups.
     *
     * @return array
     */
    public function getOrdersByCustomerGroup($count = false)
    {
        $options = array_merge(array(
            'status' => 'complete'
        ), $this->options);

        $query = $this->readConnection->select();

        $columns = array(
            'total' => 'COUNT(*)',
        );

        $salesOrderTbl = $this->resource->getTableName('sales/order');
        $customersTbl = $this->resource->getTableName('customer/entity');
        $customerGroupTbl = $this->resource->getTableName('customer_group');

        if ($count) {
            $columns = array(
                'total' => 'COUNT(sfo.entity_id)',
                'total_value' => 'SUM(sfo.grand_total)'
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);
            $query->join(array('c' => $customersTbl), 'c.entity_id = sfo.customer_id', array());
            $query->join(array('cg' => $customerGroupTbl), 'cg.customer_group_id = c.group_id');

        } else {
            // get totals.
            $totals = $this->getOrdersByCustomerGroup($options, true);

            $columns = array(
                'total' => 'COUNT(sfo.entity_id)',
                'total_percentage' => "ROUND((COUNT(sfo.entity_id) / {$totals['total']}) * 100, 2)",
                'total_value' => 'SUM(sfo.grand_total)',
            );

            $query->from(array('sfo' => $salesOrderTbl), $columns);
            $query->join(array('c' => $customersTbl), 'c.entity_id = sfo.customer_id', array());
            $query->join(array('cg' => $customerGroupTbl), 'cg.customer_group_id = c.group_id', array('name' => 'cg.customer_group_code'));

            $query->limitPage($this->options['page'], 24);
            $query->group('cg.customer_group_id');
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

        $query->where('sfo.store_id = ?', $this->options['store_id']);

        $data = $this->readConnection->fetchAll($query);

        if ($count) {
            return $data[0];
        } else {
            return $this->appendOther($totals, $data);
        }
    }
}