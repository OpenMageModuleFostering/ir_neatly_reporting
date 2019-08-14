<?php

use IR_Neatly_Exception_Api as ApiException;

class IR_Neatly_IndexController extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Mage_Core_Controller_Response_Http
     */
    protected $resp;

    /**
     * @var array
     */
    protected $stores = array();

    /**
     * @var array
     */
    protected $data = array();

    /**
     * @var IR_Neatly_Model_Reports_Sales
     */
    protected $salesReport;

    /**
     * @var IR_Neatly_Model_Reports_Reporting
     */
    protected $reporting;

    /**
     * @var array
     */
    protected $actions = array(
        'sales_totals_by_date' => 'getTotalOrders',
        'new_vs_returning_customers' => 'getNewVsReturningCustomers',
        'aggregated_totals' => 'getAggregatedTotals',
        'best_selling_products' => 'getBestSellingProducts',
        'sales_by_country' => 'getOrdersByCountry',
        'sales_by_city' =>  'getOrdersByCity',
        'sales_by_payment_method' => 'getOrdersByPaymentMethod',
        'sales_by_hour' => 'getOrdersByHour',
        'sales_by_week_day' => 'getOrdersByWeekDay',
        'items_sold_by_product_category' => 'getOrdersByProductCategory',
        'sales_by_customer_group' => 'getOrdersByCustomerGroup',
        'distinct_order_statuses' => 'getDistinctOrderStatuses',
        'customers' => 'getCustomers',
        'stores' => 'getStores',
        'meta' => 'getMeta',
    );

    /**
     * GET /neatly
     */
    public function indexAction()
    {
        $this->resp = $this->getResponse();

        $this->resp->setHeader('Content-type', 'application/json');

        $errors = array();

        $helper = Mage::helper('ir_neatly');

        // if extension is not active and enabled.
        if (!$helper->isEnabled()) {
            $errors[] = 'Neatly extension disabled.';
        }

        // if api_token not set.
        if (!$passedApiToken = $this->getRequest()->getParam('api_token')) {
            $errors[] = '"api_token" required.';
        } elseif (!$helper->isApiTokenValid($passedApiToken)) {
            $errors[] = 'Incorrect API Token.';
        }

        if (!empty($errors)) {
            $this->resp->setHttpResponseCode(403);
            $this->resp->setBody(json_encode(array('errors' => $errors)));
            return;
        }

        $this->options = array_merge(array(
            'to' => null,
            'from' => null,
            'action' => null,
            'store_id' => null,
            'page' => true,
        ), $this->getRequest()->getParams());

        $this->salesReport = Mage::getModel('ir_neatly/reports_sales', $this->options);

        $this->reporting = Mage::getModel('ir_neatly/reports_reporting', $this->options);

        // get all stores.
        $this->stores = $this->salesReport->getStores();

        // if only 1 store returned or no store_id passed in request.
        if (count($this->stores) == 1 || empty($this->options['store_id'])) {
            $this->options['store_id'] = $this->stores[0]->id;
        }

        $this->salesReport->setOptions($this->options);
        $this->customersReport = Mage::getModel('ir_neatly/reports_customers', $this->options);

        try {
            if (empty($this->options['to']) || empty($this->options['from'])) {
                throw new ApiException('"to" and "from" dates required.', 400);
            }

            $resp = array('version' => $helper->getVersion());

            // if action is an array and all requested actions exist.
            if (is_array($this->options['action']) &&
                array_intersect($this->options['action'], array_keys($this->actions)) === $this->options['action']) {
                foreach ($this->options['action'] as $action) {
                    $method = $this->actions[$action];
                    $resp[$action] = $this->{$method}();
                }
            } elseif (isset($this->actions[$this->options['action']])) {
                // if action is not an array but exists.
                $method = $this->actions[$this->options['action']];
                $resp[$this->options['action']] = $this->{$method}();
            } else {
                // get default actions (expect "customers").
                unset($this->actions['customers']);
                foreach ($this->actions as $action => $method) {
                    $resp[$action] = $this->{$method}();
                }
            }

            $this->resp->setBody(json_encode($resp));
        } catch (ApiException $e) {
            $this->resp->setHttpResponseCode($e->getCode());
            $this->resp->setBody(json_encode(array('errors' => $e->getErrors())));
        } catch (Exception $e) {
            // throw exceptions while in development.
            if (isset($_SERVER['MAGE_IS_DEVELOPER_MODE'])) {
                throw $e;
            }

            $msg = 'There is a problem with the installed Neatly extension. Please check your log files.';
            Mage::logException($e);
            $this->resp->setHttpResponseCode(500);
            $this->resp->setBody(json_encode(array('errors' => array($msg))));
        }
    }

    protected function getTotalOrders()
    {
        return $this->salesReport->getTotalOrders();
    }

    protected function getNewVsReturningCustomers()
    {
        return $this->customersReport->getNewVsReturningCustomers();
    }

    protected function getAggregatedTotals()
    {
        return $this->salesReport->getAggregatedTotals();
    }

    protected function getBestSellingProducts()
    {
        return $this->salesReport->getBestSellingProducts();
    }

    protected function getOrdersByCountry()
    {
        return $this->salesReport->getOrdersByGroup(array('group' => 'sfoa.country_id'));
    }

    protected function getOrdersByCity()
    {
        return $this->salesReport->getOrdersByGroup(array('group' => 'sfoa.city'));
    }

    protected function getOrdersByPaymentMethod()
    {
        return $this->salesReport->getOrdersByGroup(array('group' => 'sfop.method'));
    }

    protected function getOrdersByHour()
    {
        return $this->salesReport->getOrdersByPeriod(array('period' => 'hour'));
    }

    protected function getOrdersByWeekDay()
    {
        return $this->salesReport->getOrdersByPeriod(array('period' => 'dayname'));
    }

    protected function getOrdersByProductCategory()
    {
        return $this->salesReport->getOrdersByProductCategory();
    }

    protected function getOrdersByCustomerGroup()
    {
        return $this->customersReport->getOrdersByCustomerGroup();
    }

    protected function getDistinctOrderStatuses()
    {
        return $this->salesReport->getDistinctOrderStatuses();
    }

    public function getCustomers()
    {
        // if page not set.
        if (!$this->options['page']) {
            // get entire result set.
            return $this->reporting->getCustomers();
        }

        $count = $this->reporting->getCustomers(true);

        $options = array();

        // if results set is 1000 or less.
        if ($count <= 1000) {
            // return entire resp.
            $options['page_size'] = 1000;
        }

        $customers = $this->reporting->getCustomers(false, $options);
        return $this->reporting->getPagination($count, $customers);
    }

    protected function getStores()
    {
        return $this->stores;
    }

    protected function getMeta()
    {
        return $this->salesReport->getMeta();
    }
}
