<?php
/**
 * Neatly API Exception handler.
 *
 * @author Magento
 */
class IR_Neatly_Exception_Api extends Exception
{
    /**
     * An array of error messages created during the API call.
     *
     * @var array
     */
    protected $errors = array();

    /**
     * Throw an API exception.
     *
     * @param mixed $errors
     * @param mixed $code
     */
    public function __construct($errors, $code = 0)
    {
        // if response code passed as first argument
        if (is_int($errors)) {
            $code = $errors;
            $errors = array();
        }

        parent::__construct('', $code);

        $this->errors = (is_array($errors) ? $errors : array($errors));
    }

    /**
     * Get an array of error messages.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
