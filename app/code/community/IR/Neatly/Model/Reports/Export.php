<?php
function print_d()
{
    echo "<pre>\n";
    foreach (func_get_args() as $object) {
        if (is_null($object)) {
            var_dump($object);
        } else {
            print_r($object);
        }
        echo "\n";
    }
    echo "</pre>";
    exit;
}

use IR_Neatly_Exception_Api as ApiException;

class IR_Neatly_Model_Reports_Export extends IR_Neatly_Model_Reports_Abstract
{
    public function get()
    {

    }
}
