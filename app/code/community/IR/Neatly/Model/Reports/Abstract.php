<?php

abstract class IR_Neatly_Model_Reports_Abstract
{
    /**
     * @var Mage_Core_Model_Resource
     */
    protected $resource;

    /**
     * @var Magento_Db_Adapter_Pdo_Mysql
     */
    protected $readConnection;

    /**
     * @var array
     */
    public $options;

    /**
     * Set resource and read connection properties.
     */
    public function __construct($options = array())
    {
        $this->resource = Mage::getSingleton('core/resource');
        $this->readConnection = $this->resource->getConnection('core_read');

        if (!$this->readConnection instanceof Magento_Db_Adapter_Pdo_Mysql &&
            !$this->readConnection instanceof Varien_Db_Adapter_Pdo_Mysql) {
            throw new Exception('The Neatly Magento extension only supports MySQL databases.');
        }

        // set options.
        $this->setOptions($options);

        // if a valid order not set.
        if (!in_array($this->options['order'], array('ASC', 'DESC'))) {
            $this->options['order'] = 'DESC';
        }

        $groups = array('DAY', 'MONTH', 'YEAR');

        if (!in_array(strtoupper($this->options['group_by']), $groups)) {
            $this->options['group_by'] = 'DAY';
        }

        switch ($this->options['group_by']) {
            case 'YEAR':
                $this->options['date_format'] = '%Y';
                break;
            case 'MONTH':
                $this->options['date_format'] = '%Y-%m';
            default:
                $this->options['date_format'] = '%Y-%m-%d';
        }
    }

    /**
     * Get pagination data for a given total.
     *
     * @param int $total
     * @param array $data
     * @return stdClass
     */
    public function getPagination($total, $data)
    {
        $lastPage = 0;

        $from = (($this->options['page'] - 1) * $this->options['page_size']) + 1;
        $to = ($from - 1) + count($data);

        $lastPage = ceil($total / $this->options['page_size']);

        return (object)array(
            'total'=> (int)$total,
            'total_on_this_page' => (int)count($data),
            'per_page'=> (int)$this->options['page_size'],
            'current_page'=> (int)$this->options['page'],
            'last_page'=> (int)$lastPage,
            'from'=> (int)$from,
            'to'=> (int)$to,
            'data' => $data
        );
    }

    /**
     * Get a set of dates between 2 given ranges.
     *
     * @param string|int $from
     * @param string|int $to
     * @param string $range
     */
    public function getDates($from, $to, $range = 'DAY')
    {
        if (!in_array($range, array(
            'DAY',
            'WEEK',
            'MONTH',
            'YEAR',
        ))) {
            $range = 'DAY';
        }

        $from = gmdate("Y-m-d", strtotime($from));
        $to = gmdate("Y-m-d", strtotime($to));

        $dates[] = $from;

        $currentDate = $from;

        // While the current date is less than the end date
        while ($currentDate < $to) {
            $currentDate = gmdate("Y-m-d", strtotime("+1 {$range}", strtotime($currentDate)));
            $dates[] = $currentDate;
        }

        return $dates;
    }

    /**
     * Combine a set of dates and a set of objects from a database query. The
     * objects passed must contain a "date" property.
     *
     * @param array $dateRange
     * @param array $objects
     * @param array $keys The properties you want to use from the objects.
     * @return array
     */
    public function datesCombine($dateRange, $objects, $keys)
    {
        $dates = array();

        // remove date from keys.
        unset($keys[array_search('date', $keys)]);

        foreach ($dateRange as $i => $date) {
            $from = strtotime($date);
            $to = strtotime("{$dateRange[$i]} 23:59:59");

            foreach ($objects as $object) {
                $period = strtotime($object['date']);
                if ($period >= $from && $period < $to) {
                    foreach ($keys as $key) {
                        $dates[$date][$key] = is_numeric($object[$key]) ? floatval($object[$key]) : $object[$key];
                    }
                } elseif (!isset($dates[$date])) { // if date has not already been set by a previous object.
                    foreach ($keys as $key) {
                        $dates[$date][$key] = 0;
                    }
                }
            }
        }

        return $dates;
    }

    /**
     * Append other.
     *
     * @param int $total
     * @param array $objects
     * @return array
     */
    public function appendOther($totals, $objects)
    {
        $total['total'] = 0;
        $total['percentage'] = 0;
        $total['value'] = 0;

        foreach ($objects as $object) {
            $total['total'] += $object['total'];
            $total['percentage'] += $object['total_percentage'];
            $total['value'] += $object['total_value'];
        }

        // if there are no "other" values there is no need to append.
        if (($totals['total'] - $total['total']) == 0) {
            return $objects;
        }

        $objects[] = array(
            'total' => $totals['total'] - $total['total'],
            'total_percentage' => 100 - $total['percentage'],
            'total_value' => $totals['total_value'] - $total['value'],
            'name' => 'Other',
        );

        return $objects;
    }

    /**
     * Set report options.
     *
     * @param array $options
     */
    public function setOptions($options = array())
    {
        // set global default options.
        $this->options = array_merge(array(
            'from' => null,
            'to' => null,
            'sort' => null,
            'order' => 'DESC',
            'page_size' => 10,
            'page' => 1,
            'group_by' => 'DAY',
            'date_format' => '%Y-%m-%d',
            'store_id' => null,
        ), $options);
    }
}