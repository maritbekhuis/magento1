<?php
    /**
     *  Rewrite getShippingAddress to retrieve pakjegemak address if possible
     *
     *
     *  @author     Reindert Vetter <info@myparcel.nl>
     *  @copyright  2016 MyParcelNL
     *  @since      File available since Release 1.5.0
     *  @license    http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
     *
     *  Class TIG_MyParcel2014_Model_Sales_Order
     */
    class TIG_MyParcel2014_Model_Sales_Order extends Mage_Sales_Model_Order
    {

        /**
         * Retrieve order shipping address
         *
         * @return Mage_Sales_Model_Order_Address
         */
        public function getShippingAddress() {

            $helper = Mage::helper('tig_myparcel');
            $usePgAddress = $helper->getConfig('pakjegemak_use_shipment_address') === '1';

            $parentFunctions = debug_backtrace();
            if($parentFunctions[3]['function'] == '_getConsignmentData') {
                $usePgAddress = false;
            }

            $pgAddress = false;
            $shippingAddress = false;

            foreach ($this->getAddressesCollection() as $address) {

                /**
                 * Get Pakjegemak address
                 */
                if ($address->getAddressType()=='pakje_gemak' && !$address->isDeleted() && $usePgAddress) {
                    $pgAddress = $address;
                }
                if ($address->getAddressType()=='shipping' && !$address->isDeleted()) {
                    $shippingAddress = $address;
                }
            }

            if($pgAddress) {
                return $pgAddress;
            }

            if($shippingAddress) {
                return $shippingAddress;
            }

            return false;
        }

    }