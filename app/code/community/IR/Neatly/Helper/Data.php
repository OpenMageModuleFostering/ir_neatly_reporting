<?php
/**
 * Neatly data helper.
 *
 * @author iResources
 */
class IR_Neatly_Helper_Data extends Mage_Core_Helper_Data
{
    const VERSION = '1.1.2.0';

    /**
     * Checks whether JSON can be rendered at the /neatly endpoint.
     *
     * @param integer|string|Mage_Core_Model_Store $store
     * @return boolean
     */
    public function isEnabled($store = null)
    {
        $isActive = Mage::getConfig()->getModuleConfig('IR_Neatly')->is('active', 'true');
        $isEnabled = !Mage::getStoreConfig('advanced/modules_disable_output/IR_Neatly');

        return $isActive && $isEnabled;
    }

    /**
     * Checks whether the passed API token is valid.
     *
     * @param string $passedApiToken
     * @return bool
     */
    public function isApiTokenValid($passedApiToken = null)
    {
        # REMOVE AFTER DEVELOPMENT
        return true;

        if (!$passedApiToken) {
            return false;
        }

        if (!$apiToken = Mage::helper('core')->decrypt(Mage::getStoreConfig('ir_neatly/security/neatly_api_token'))) {
            return false;
        }

        if ($passedApiToken !== $apiToken) {
            return false;
        }

        return true;
    }

    /**
     * Get the plugin version.
     *
     * @return string
     */
    public function getVersion()
    {
        return self::VERSION;
    }
}
